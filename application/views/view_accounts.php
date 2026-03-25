<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
    <div class="va-wrap">

        <!-- ══ TOP BAR ══ -->
        <div class="va-topbar">
            <div>
                <h1 class="va-page-title">
                    <i class="fa fa-book"></i> Account Transactions
                </h1>
                <ul class="va-breadcrumb">
                    <li><a href="<?= base_url('admin') ?>"><i class="fa fa-home"></i> Home</a></li>
                    <li><a href="<?= base_url('account/account_book') ?>">Accounts</a></li>

                    <!-- <li>Accounts</li> -->
                    <li>View Transactions</li>
                </ul>
            </div>
            <div class="va-session-badge">
                <span class="va-badge-label">Session</span>
                <span
                    class="va-badge-val"><?= htmlspecialchars($session_year ?? date('Y') . '–' . (date('Y') + 1)) ?></span>
            </div>
        </div>

        <!-- ══ FILTER CARD ══ -->
        <div class="va-card">
            <div class="va-card-head">
                <i class="fa fa-sliders"></i>
                <h3>Filter Transactions</h3>
            </div>
            <div class="va-card-body">
                <div class="va-filter-row">

                    <div class="va-fc">
                        <label class="va-label" for="accountType">Account</label>
                        <div class="va-select-wrap">
                            <select id="accountType" class="va-select">
                                <option selected disabled>Select Account</option>
                                <option value="All">All Accounts</option>
                                <?php if (!empty($accountTypes)): ?>
                                    <?php foreach ($accountTypes as $key): ?>
                                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($key) ?></option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <option disabled>No accounts available</option>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>

                    <div class="va-fc">
                        <label class="va-label" for="fromDate">From Date</label>
                        <input type="date" id="fromDate" class="va-input">
                    </div>

                    <div class="va-fc">
                        <label class="va-label" for="toDate">To Date</label>
                        <input type="date" id="toDate" class="va-input">
                    </div>

                    <div class="va-fc-btn">
                        <label class="va-label">&nbsp;</label>
                        <button class="va-btn va-btn-teal" id="showaccount">
                            <i class="fa fa-search"></i> Show Transactions
                        </button>
                    </div>

                </div>
            </div>
        </div>

        <!-- ══ TRANSACTIONS CARD ══ -->
        <div class="va-card">
            <div class="va-card-head">
                <i class="fa fa-table"></i>
                <h3>
                    Transactions
                    <span id="vaAccLabel"
                        style="font-family:'DM Sans',sans-serif;font-size:13px;font-weight:600;color:var(--ab-teal)"></span>
                </h3>
                <div class="va-head-right">
                    <span class="va-pill" id="vaRowCount">0 records</span>
                </div>
            </div>

            <!-- DataTable sits inside here -->
            <div class="va-tbl-outer" id="vaTblOuter">
                <table id="accountTable" class="va-tbl">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Particulars</th>
                            <th>Cr Amt</th>
                            <th>Dr Amt</th>
                            <th>Mode</th>
                        </tr>
                    </thead>
                    <tbody id="vouchersTableBody">
                        <tr>
                            <td colspan="6" style="padding:0;border:none">
                                <div class="va-empty">
                                    <i class="fa fa-search"></i>
                                    <p>Select an account &amp; date range, then click <strong>Show Transactions</strong>
                                    </p>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td colspan="2"></td>
                            <td style="font-weight:700">Overall Total</td>
                            <td id="totalCrAmt">₹0</td>
                            <td id="totalDrAmt">₹0</td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
        </div>

    </div><!-- /va-wrap -->
</div>
<div class="va-toast-wrap" id="vaToasts"></div>



<script>
document.addEventListener('DOMContentLoaded', function () {
    var $ = window.jQuery;

    /* ── Financial-year default dates ── */
    (function () {
        var t  = new Date();
        var fy = (t.getMonth() + 1) >= 4
            ? new Date(t.getFullYear(),     3, 1)
            : new Date(t.getFullYear() - 1, 3, 1);
        function fmt(d) {
            return d.getFullYear() + '-'
                + ('0' + (d.getMonth() + 1)).slice(-2) + '-'
                + ('0' + d.getDate()).slice(-2);
        }
        document.getElementById('fromDate').value = fmt(fy);
        document.getElementById('toDate').value   = fmt(t);
    })();

    /* ── Toast ── */
    function toast(msg, type) {
        var el  = document.createElement('div');
        el.className = 'va-toast va-toast-' + type;
        var ico = type === 's' ? 'check-circle'
                : type === 'e' ? 'times-circle'
                : 'exclamation-triangle';
        el.innerHTML = '<i class="fa fa-' + ico + '"></i> ' + msg;
        document.getElementById('vaToasts').appendChild(el);
        setTimeout(function () {
            el.classList.add('va-hiding');
            setTimeout(function () { el.remove(); }, 320);
        }, 3200);
    }

    /* ── Type badge ── */
    function badge(t) {
        if (!t) return '<span class="va-badge-gn">—</span>';
        var l = (t + '').toLowerCase();
        var c = (l.indexOf('cr') > -1 || l === 'credit') ? 'va-badge-cr'
              : (l.indexOf('dr') > -1 || l === 'debit')  ? 'va-badge-dr'
              : 'va-badge-gn';
        return '<span class="' + c + '">' + t + '</span>';
    }

    /* ── Format rupee ── */
    function fmtAmt(n) {
        var s = typeof numberFormat === 'function'
            ? numberFormat(n)
            : n.toLocaleString('en-IN');
        return '₹' + s;
    }

    /* ── DataTables config ── */
    var dtCfg = {
        paging:     true,
        searching:  true,
        ordering:   true,
        info:       true,
        responsive: true,
        lengthMenu: [10, 25, 50, 100],
        autoWidth:  false,
        dom: '<"cb-dt-toolbar"Bfl>rt<"cb-dt-footer"ip>',
        buttons: [
            { extend: 'pdfHtml5', text: '<i class="fa fa-file-pdf-o"></i> PDF',    orientation: 'landscape', pageSize: 'LEGAL' },
            { extend: 'copy',     text: '<i class="fa fa-copy"></i> Copy'     },
            { extend: 'csv',      text: '<i class="fa fa-file-text-o"></i> CSV'    },
            { extend: 'excel',    text: '<i class="fa fa-file-excel-o"></i> Excel' },
            { extend: 'print',    text: '<i class="fa fa-print"></i> Print'   }
        ],
        language: { emptyTable: 'No transactions found', search: 'Search:' },
        order: []
    };

    /* ── NO page-load DataTable init ── */
    /* Initialising on an empty tbody causes "unknown parameter '1'" warning */

    /* ── Show Transactions button ── */
    $(document).on('click', '#showaccount', function () {
        var fromRaw = $('#fromDate').val();
        var toRaw   = $('#toDate').val();
        var from    = typeof formatDateToYMD === 'function' ? formatDateToYMD(fromRaw) : fromRaw;
        var to      = typeof formatDateToYMD === 'function' ? formatDateToYMD(toRaw)   : toRaw;
        var acType  = $('#accountType').val();

        if (!acType || acType === 'Select Account') {
            toast('Please select an account first.', 'w');
            return;
        }
        if (!from || !to) {
            toast('Please select a valid date range.', 'w');
            return;
        }

        var $btn = $(this);
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Loading…');

        $.ajax({
            url:      '<?= site_url("account/view_accounts") ?>',
            type:     'POST',
            data:     { fromDate: from, toDate: to, accountType: acType },
            dataType: 'json',

            success: function (resp) {
                $btn.prop('disabled', false)
                    .html('<i class="fa fa-search"></i> Show Transactions');

                if (resp.status !== 'success') {
                    toast(resp.message || 'Failed to load data.', 'e');
                    return;
                }

                /* Update heading */
                document.getElementById('vaAccLabel').textContent =
                    acType === 'All' ? ' — All Accounts' : ' — ' + acType;

                /* Destroy existing DataTable before touching tbody */
                if ($.fn.DataTable.isDataTable('#accountTable')) {
                    $('#accountTable').DataTable().destroy();
                }

                var body  = document.getElementById('vouchersTableBody');
                body.innerHTML = '';

                var data  = resp.data || [];   /* defined HERE inside success — nowhere else */
                var totCr = 0, totDr = 0;

                if (data.length) {
                    /* Build rows */
                    data.forEach(function (v) {
                        var cr = parseFloat(v['Cr Amt']) || 0;
                        var dr = parseFloat(v['Dr Amt']) || 0;
                        totCr += cr;
                        totDr += dr;
                        var tr = document.createElement('tr');
                        tr.innerHTML =
                            '<td class="va-date-cell">'                         + (v.Date        || '—')   + '</td>' +
                            '<td>'                                               + badge(v.Type)            + '</td>' +
                            '<td style="max-width:280px;word-break:break-word">' + (v.Particulars || '—')   + '</td>' +
                            '<td class="va-cr">'                                + fmtAmt(cr)               + '</td>' +
                            '<td class="va-dr">'                                + fmtAmt(dr)               + '</td>' +
                            '<td class="va-mode-cell">'                         + (v.Mode        || 'N/A') + '</td>';
                        body.appendChild(tr);
                    });

                    var rc = document.getElementById('vaRowCount');
                    rc.textContent   = data.length + ' record' + (data.length !== 1 ? 's' : '');
                    rc.style.display = 'inline-flex';

                    /* Init DataTables ONLY after real rows are in tbody */
                    setTimeout(function () {
                        $('#accountTable').DataTable(dtCfg);
                    }, 80);

                } else {
                    /* Empty result — plain HTML only, no DataTables */
                    /* DataTables cannot handle a colspan row — causes the warning */
                    body.innerHTML =
                        '<tr><td colspan="6" style="padding:0;border:none">' +
                        '<div class="va-empty"><i class="fa fa-inbox"></i>' +
                        '<p>No transactions found for the selected filters.</p>' +
                        '</div></td></tr>';
                    document.getElementById('vaRowCount').style.display = 'none';
                }

                /* Totals */
                document.getElementById('totalCrAmt').textContent = fmtAmt(totCr);
                document.getElementById('totalDrAmt').textContent = fmtAmt(totDr);

                toast('Loaded ' + data.length + ' transaction' +
                      (data.length !== 1 ? 's' : '') + '.', 's');
            },

            error: function () {
                $btn.prop('disabled', false)
                    .html('<i class="fa fa-search"></i> Show Transactions');
                toast('Network error — please try again.', 'e');
            }
        });
    });

}); // end DOMContentLoaded
</script>


<style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap');

    /* ══ TOKENS — identical to account_book ══ */
    :root {
        --ab-navy: #0b1f3a;
        --ab-teal: #0e7490;
        --ab-sky: #e0f2fe;
        --ab-green: #16a34a;
        --ab-red: #dc2626;
        --ab-amber: #d97706;
        --ab-blue: #2563eb;
        --ab-text: #1e293b;
        --ab-muted: #64748b;
        --ab-border: #e2e8f0;
        --ab-white: #ffffff;
        --ab-bg: #f1f5f9;
        --ab-shadow: 0 1px 14px rgba(11, 31, 58, .08);
        --ab-radius: 12px;
    }

    * {
        box-sizing: border-box;
    }

    /* ══ SHELL ══ */
    .va-wrap {
        font-family: 'DM Sans', sans-serif;
        background: var(--ab-bg);
        color: var(--ab-text);
        padding: 24px 20px 60px;
        min-height: 100%;
    }

    /* ══ TOP BAR ══ */
    .va-topbar {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 22px;
    }

    .va-page-title {
        font-family: 'Playfair Display', serif;
        font-size: 22px;
        font-weight: 700;
        color: var(--ab-navy);
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 5px;
    }

    .va-page-title i {
        color: var(--ab-teal);
    }

    .va-breadcrumb {
        display: flex;
        align-items: center;
        list-style: none;
        margin: 0;
        padding: 0;
        font-size: 12.5px;
        color: var(--ab-muted);
    }

    .va-breadcrumb a {
        color: var(--ab-teal);
        text-decoration: none;
        font-weight: 500;
    }

    .va-breadcrumb li+li::before {
        content: '/';
        margin: 0 7px;
        color: #cbd5e1;
    }

    .va-session-badge {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 2px;
    }

    .va-badge-label {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .6px;
        text-transform: uppercase;
        color: var(--ab-muted);
    }

    .va-badge-val {
        font-family: 'Playfair Display', serif;
        font-size: 17px;
        font-weight: 700;
        color: var(--ab-navy);
        line-height: 1;
    }

    /* ══ CARD ══ */
    .va-card {
        background: var(--ab-white);
        border-radius: var(--ab-radius);
        box-shadow: var(--ab-shadow);
        border: 1px solid var(--ab-border);
        overflow: hidden;
        margin-bottom: 16px;
    }

    .va-card-head {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 13px 18px;
        border-bottom: 1px solid var(--ab-border);
        background: linear-gradient(90deg, var(--ab-sky) 0%, var(--ab-white) 100%);
    }

    .va-card-head i {
        color: var(--ab-teal);
        font-size: 14px;
    }

    .va-card-head h3 {
        font-family: 'Playfair Display', serif;
        font-size: 14.5px;
        font-weight: 700;
        color: var(--ab-navy);
        margin: 0;
    }

    .va-card-head .va-head-right {
        margin-left: auto;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .va-card-body {
        padding: 18px;
        background: var(--ab-white);
    }

    /* ══ LABELS + INPUTS (identical to account_book) ══ */
    .va-label {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
        color: var(--ab-muted);
        display: block;
        margin-bottom: 5px;
    }

    .va-input,
    .va-select {
        height: 38px;
        padding: 0 10px;
        border: 1.5px solid var(--ab-border);
        border-radius: 8px;
        font-size: 13.5px;
        font-family: 'DM Sans', sans-serif;
        color: var(--ab-text);
        background: #fafcff;
        outline: none;
        width: 100%;
        transition: border-color .13s, box-shadow .13s;
    }

    .va-select {
        padding-right: 32px;
        appearance: none;
        cursor: pointer;
    }

    .va-select-wrap {
        position: relative;
    }

    .va-select-wrap::after {
        content: '\f078';
        font-family: 'Font Awesome 5 Free';
        font-weight: 900;
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--ab-muted);
        font-size: 10px;
        pointer-events: none;
    }

    .va-input:focus,
    .va-select:focus {
        border-color: var(--ab-teal);
        box-shadow: 0 0 0 3px rgba(14, 116, 144, .10);
    }

    /* ══ FILTER ROW ══ */
    .va-filter-row {
        display: flex;
        flex-wrap: wrap;
        gap: 14px;
        align-items: flex-end;
    }

    .va-fc {
        flex: 1;
        min-width: 160px;
        display: flex;
        flex-direction: column;
    }

    .va-fc-btn {
        flex: 0 0 auto;
        min-width: 150px;
        display: flex;
        flex-direction: column;
    }

    /* ══ BUTTON (matches account_book ab-btn) ══ */
    .va-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        padding: 9px 20px;
        border-radius: 8px;
        border: none;
        font-family: 'DM Sans', sans-serif;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: opacity .13s, transform .1s;
        white-space: nowrap;
        width: 100%;
        height: 38px;
    }

    .va-btn:hover:not(:disabled) {
        opacity: .86;
        transform: translateY(-1px);
    }

    .va-btn:disabled {
        opacity: .45;
        cursor: not-allowed;
        transform: none;
    }

    .va-btn-teal {
        background: var(--ab-amber);
        color: #fff;
    }

    /* ══ RECORD PILL ══ */
    .va-pill {
        display: none;
        font-size: 11px;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 20px;
        background: #f0fdf4;
        color: var(--ab-green);
        border: 1px solid #bbf7d0;
    }

    /* ══ TRANSACTIONS TABLE ══ */
    .va-tbl-outer {
        overflow-x: auto;
        border-radius: 8px;
        border: 1px solid var(--ab-border);
    }

    table.va-tbl {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        font-family: 'DM Sans', sans-serif;
    }

    table.va-tbl thead th {
        background: var(--ab-navy);
        color: rgba(255, 255, 255, .85);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
        padding: 11px 14px;
        text-align: left;
        white-space: nowrap;
    }

    table.va-tbl thead th:first-child {
        border-radius: 8px 0 0 0;
    }

    table.va-tbl thead th:last-child {
        border-radius: 0 8px 0 0;
    }

    table.va-tbl tbody tr {
        border-bottom: 1px solid var(--ab-border);
        transition: background .1s;
    }

    table.va-tbl tbody tr:hover {
        background: var(--ab-sky);
    }

    table.va-tbl tbody td {
        padding: 10px 14px;
        color: var(--ab-text);
        background: transparent;
    }

    /* Amount colors */
    .va-cr {
        color: var(--ab-green) !important;
        font-variant-numeric: tabular-nums;
        font-weight: 600;
    }

    .va-dr {
        color: var(--ab-red) !important;
        font-variant-numeric: tabular-nums;
        font-weight: 600;
    }

    /* Type badge */
    .va-badge-cr {
        display: inline-flex;
        align-items: center;
        padding: 2px 9px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        background: #f0fdf4;
        color: var(--ab-green);
        border: 1px solid #bbf7d0;
    }

    .va-badge-dr {
        display: inline-flex;
        align-items: center;
        padding: 2px 9px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        background: #fef2f2;
        color: var(--ab-red);
        border: 1px solid #fecaca;
    }

    .va-badge-gn {
        display: inline-flex;
        align-items: center;
        padding: 2px 9px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 600;
        background: #f0f9ff;
        color: var(--ab-teal);
        border: 1px solid #bae6fd;
    }

    /* Date cell */
    .va-date-cell {
        color: var(--ab-muted);
        font-size: 12px;
    }

    /* Mode cell */
    .va-mode-cell {
        color: var(--ab-muted);
        font-size: 12.5px;
    }

    /* Footer totals row */
    table.va-tbl tfoot tr {
        background: var(--ab-navy);
    }

    table.va-tbl tfoot td {
        padding: 10px 14px;
        font-size: 13px;
        font-weight: 700;
        color: rgba(255, 255, 255, .9);
        background: var(--ab-navy) !important;
    }

    table.va-tbl tfoot td:first-child {
        border-radius: 0 0 0 8px;
    }

    table.va-tbl tfoot td:last-child {
        border-radius: 0 0 8px 0;
    }

    #totalCrAmt {
        color: #86efac !important;
        font-variant-numeric: tabular-nums;
    }

    #totalDrAmt {
        color: #fca5a5 !important;
        font-variant-numeric: tabular-nums;
    }

    /* ══ DataTables chrome override ══ */
    .va-wrap .dataTables_wrapper {
        padding: 13px 16px 16px;
        background: var(--ab-white);
    }

    .va-wrap .dataTables_wrapper .dataTables_filter input,
    .va-wrap .dataTables_wrapper .dataTables_length select {
        background: #fafcff !important;
        border: 1.5px solid var(--ab-border) !important;
        color: var(--ab-text) !important;
        border-radius: 7px !important;
        padding: 4px 10px !important;
        font-family: 'DM Sans', sans-serif !important;
    }

    .va-wrap .dataTables_wrapper .dataTables_filter input:focus,
    .va-wrap .dataTables_wrapper .dataTables_length select:focus {
        border-color: var(--ab-teal) !important;
        box-shadow: 0 0 0 3px rgba(14, 116, 144, .10) !important;
        outline: none !important;
    }

    .va-wrap .dataTables_wrapper .dataTables_info,
    .va-wrap .dataTables_wrapper .dataTables_length,
    .va-wrap .dataTables_wrapper .dataTables_filter {
        color: var(--ab-muted) !important;
        font-size: 12px !important;
        font-family: 'DM Sans', sans-serif !important;
    }

    .va-wrap .dataTables_wrapper .dataTables_paginate .paginate_button {
        border-radius: 7px !important;
        color: var(--ab-muted) !important;
        font-size: 12px !important;
        border: none !important;
        background: transparent !important;
        font-family: 'DM Sans', sans-serif !important;
    }

    .va-wrap .dataTables_wrapper .dataTables_paginate .paginate_button.current {
        background: var(--ab-teal) !important;
        color: #fff !important;
        border: none !important;
    }

    .va-wrap .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
        background: var(--ab-sky) !important;
        color: var(--ab-teal) !important;
    }

    .va-wrap div.dt-buttons .dt-button {
        background: var(--ab-white) !important;
        color: var(--ab-text) !important;
        border: 1.5px solid var(--ab-border) !important;
        border-radius: 7px !important;
        font-size: 12px !important;
        padding: 5px 12px !important;
        font-family: 'DM Sans', sans-serif !important;
        transition: all .13s !important;
    }

    .va-wrap div.dt-buttons .dt-button:hover {
        background: var(--ab-sky) !important;
        border-color: var(--ab-teal) !important;
        color: var(--ab-teal) !important;
    }

    /* ══ EMPTY STATE ══ */
    .va-empty {
        padding: 52px 20px;
        text-align: center;
        background: var(--ab-white);
    }

    .va-empty i {
        font-size: 30px;
        color: var(--ab-muted);
        opacity: .4;
        display: block;
        margin-bottom: 12px;
    }

    .va-empty p {
        font-size: 13px;
        color: var(--ab-muted);
        margin: 0;
    }

    .va-empty strong {
        color: var(--ab-teal);
    }

    /* ══ TOAST ══ */
    .va-toast-wrap {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 99999;
        display: flex;
        flex-direction: column;
        gap: 8px;
        pointer-events: none;
    }

    .va-toast {
        padding: 11px 16px;
        border-radius: 10px;
        color: #fff;
        font-family: 'DM Sans', sans-serif;
        font-size: 13px;
        font-weight: 600;
        box-shadow: 0 4px 18px rgba(0, 0, 0, .2);
        display: flex;
        align-items: center;
        gap: 8px;
        animation: va-tin .22s ease;
        max-width: 320px;
        pointer-events: auto;
        transition: opacity .3s;
    }

    .va-toast.va-hiding {
        opacity: 0;
    }

    .va-toast-s {
        background: var(--ab-green);
    }

    .va-toast-e {
        background: var(--ab-red);
    }

    .va-toast-w {
        background: var(--ab-amber);
    }

    @keyframes va-tin {
        from {
            transform: translateX(20px);
            opacity: 0
        }

        to {
            transform: translateX(0);
            opacity: 1
        }
    }

    /* ══ PRINT ══ */
    @media print {

        .va-topbar,
        .va-card:first-of-type,
        .va-toast-wrap {
            display: none !important;
        }

        .va-card {
            box-shadow: none;
            border: none;
        }
    }
</style>