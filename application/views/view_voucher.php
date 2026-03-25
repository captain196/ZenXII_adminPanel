<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
    <div class="vv-wrap">

  <!-- Top bar -->
  <div class="vv-topbar">
    <div>
      <h1 class="vv-page-title"><i class="fa fa-list-alt"></i> View Vouchers</h1>
      <ul class="vv-breadcrumb">
        <li><a href="<?= base_url('admin') ?>"><i class="fa fa-home"></i> Home</a></li>
        <li>Accounts</li>
        <li>View Vouchers</li>
      </ul>
    </div>
    <div class="vv-session-badge">
      <span class="vv-badge-label">Session</span>
      <span class="vv-badge-val"><?= htmlspecialchars($session_year ?? date('Y').'-'.(date('Y')+1)) ?></span>
    </div>
  </div>

  <!-- Filter card -->
  <div class="vv-card">
    <div class="vv-card-head">
      <i class="fa fa-sliders"></i>
      <h3>Filter Vouchers</h3>
    </div>
    <div class="vv-card-body">
      <div class="vv-filter-row">
        <div class="vv-fc">
          <label class="vv-label" for="voucherType">Voucher Type</label>
          <div class="vv-select-wrap">
            <select class="vv-select" id="voucherType">
              <option value="" disabled selected>Select Voucher Type</option>
              <option value="All">All</option>
              <option value="Payment">Payment</option>
              <option value="Received">Receipt</option>
              <option value="Journal">Journal</option>
              <option value="Contra">Contra</option>
            </select>
          </div>
        </div>
        <div class="vv-fc">
          <label class="vv-label" for="fromDate">From Date</label>
          <input type="date" class="vv-input" id="fromDate">
        </div>
        <div class="vv-fc">
          <label class="vv-label" for="toDate">To Date</label>
          <input type="date" class="vv-input" id="toDate">
        </div>
        <div class="vv-fc-btn">
          <label class="vv-label">&nbsp;</label>
          <button class="vv-btn vv-btn-teal" id="showBtn" disabled>
            <i class="fa fa-search"></i> Show
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- Vouchers table card -->
  <div class="vv-card">
    <div class="vv-card-head">
      <i class="fa fa-table"></i>
      <h3>Vouchers</h3>
      <div class="vv-card-head-right">
        <span class="vv-pill" id="vvRowCount">0 records</span>
      </div>
    </div>

    <div class="vv-tbl-outer" id="vvTblOuter" style="display:none">
      <table id="voucherTable" class="vv-tbl">
        <thead>
          <tr>
            <th>Date</th>
            <th>Type</th>
            <th>Particulars</th>
            <th>Payment Mode</th>
            <th>Debit Amt</th>
            <th>Credit Amt</th>
          </tr>
        </thead>
        <tbody>
          <!-- Kept empty; DataTables initialised with data API to avoid colspan-row errors -->
        </tbody>
      </table>
    </div>

    <!-- Initial empty state shown OUTSIDE the table (before first search) -->
    <div id="vvEmptyState" class="vv-empty" style="display:block">
      <i class="fa fa-search"></i>
      <p>Select a voucher type &amp; date range, then click <strong>Show</strong></p>
    </div>

    <!-- Reference row (shown on row select) -->
    <div class="vv-ref-row" id="vvRefRow">
      <label class="vv-label">Reference :</label>
      <input type="text" id="refer" class="vv-input" readonly>
    </div>

    <!-- Bottom bar -->
    <div class="vv-bottom-bar">
      <a href="<?= base_url('account/vouchers') ?>" class="vv-btn vv-btn-ghost">
        <i class="fa fa-arrow-left"></i> Back to Make Vouchers
      </a>
    </div>
  </div>

    </div>
</div>
<div class="vv-toast-wrap" id="vvToasts"></div>


<script>
/* Poll every 50ms until jQuery AND DataTables are both available */
(function waitForLibs() {
    if (typeof window.jQuery === 'undefined' || typeof window.jQuery.fn.DataTable === 'undefined') {
        return setTimeout(waitForLibs, 50);
    }
    var $ = window.jQuery;
    (function init($) {

        /* ── Toast ── */
        function toast(msg, type) {
            var el = document.createElement('div');
            el.className = 'vv-toast vv-toast-' + type;
            el.innerHTML = '<i class="fa fa-exclamation-circle"></i> ' + msg;
            document.getElementById('vvToasts').appendChild(el);
            setTimeout(function() {
                el.classList.add('vv-hiding');
                setTimeout(function() {
                    el.remove();
                }, 320);
            }, 3200);
        }

        /* ── Type badge ── */
        function typeBadge(t) {
            if (!t) return '<span class="vv-type-badge vv-type-other">—</span>';
            var l = t.toLowerCase();
            var c = l === 'payment' ? 'vv-type-payment' :
                (l === 'received' || l === 'receipt') ? 'vv-type-receipt' :
                l === 'journal' ? 'vv-type-journal' :
                l === 'contra' ? 'vv-type-contra' :
                'vv-type-other';
            return '<span class="vv-type-badge ' + c + '">' + t + '</span>';
        }

        /* ── Format amount ── */
        function fmtAmt(n) {
            return typeof numberFormat === 'function' ? numberFormat(n) : n.toLocaleString('en-IN');
        }

        /* ── DataTables instance (created once, reused with .clear().rows.add()) ── */
        var dtInstance = null;

        /* ── Row metadata store (indexed by DT row index) so we survive redraws ── */
        var rowMeta = []; // [{refer, id, type}, ...]

        /* ── Init DataTable once — empty, data-API driven ── */
        function initDT() {
            dtInstance = $('#voucherTable').DataTable({
                paging: true,
                searching: true,
                ordering: true,
                info: true,
                responsive: true,
                lengthMenu: [5, 10, 15, 20],
                autoWidth: false,
                dom: '<"top"Bfl>rt<"bottom"ip>',
                buttons: [{
                        extend: 'pdfHtml5',
                        orientation: 'landscape',
                        pageSize: 'LEGAL'
                    },
                    'copy', 'csv', 'excel', 'print'
                ],
                language: {
                    emptyTable: 'No vouchers found for the selected criteria.'
                },
                order: [],
                /* Column definitions — tell DT data is in plain array, no DOM reading */
                columns: [{
                        title: 'Date'
                    },
                    {
                        title: 'Type'
                    },
                    {
                        title: 'Particulars'
                    },
                    {
                        title: 'Payment Mode'
                    },
                    {
                        title: 'Debit Amt'
                    },
                    {
                        title: 'Credit Amt'
                    }
                ],
                data: [] // start empty
            });
        }

        /* ── Fetch server date → set defaults ── */
        $.ajax({
            url: '<?= site_url("account/get_server_date") ?>',
            type: 'GET',
            dataType: 'json',
            success: function(r) {
                if (!r || !r.date) return;
                var p = r.date.split('-');
                var today = new Date(p[2], p[1] - 1, p[0]);
                var fy = (today.getMonth() + 1) >= 4 ?
                    new Date(today.getFullYear(), 3, 1) :
                    new Date(today.getFullYear() - 1, 3, 1);

                function pad(d) {
                    return d.getFullYear() + '-' + ('0' + (d.getMonth() + 1)).slice(-2) + '-' + (
                        '0' + d.getDate()).slice(-2);
                }
                document.getElementById('fromDate').value = pad(fy);
                document.getElementById('toDate').value = pad(today);
            }
        });

        /* ── Enable Show btn when type selected ── */
        document.getElementById('voucherType').addEventListener('change', function() {
            document.getElementById('showBtn').disabled = !this.value;
        });

        /* ── Show button click ── */
        document.getElementById('showBtn').addEventListener('click', function() {
            var vt = document.getElementById('voucherType').value;
            var from = document.getElementById('fromDate').value;
            var to = document.getElementById('toDate').value;

            var $btn = $(this);
            $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Loading…');

            /* Reset reference row */
            document.getElementById('vvRefRow').style.display = 'none';
            document.getElementById('refer').value = '';

            $.ajax({
                url: '<?= site_url("account/show_vouchers") ?>',
                method: 'POST',
                data: {
                    from_date: from,
                    to_date: to,
                    voucher_type: vt
                },
                success: function(resp) {
                    $btn.prop('disabled', false).html('<i class="fa fa-search"></i> Show');

                    var vouchers = typeof resp === 'string' ? JSON.parse(resp) : resp;
                    var rc = document.getElementById('vvRowCount');
                    var emptyDiv = document.getElementById('vvEmptyState');
                    var tblOuter = document.getElementById('vvTblOuter');

                    /* Build row data array & metadata */
                    rowMeta = [];
                    var rows = [];

                    if (vouchers && vouchers.length) {
                        vouchers.forEach(function(v) {
                            var drAmt = parseFloat(v['Dr. Amt']) || 0;
                            var crAmt = parseFloat(v['Cr. Amt']) || 0;

                            rowMeta.push({
                                refer: v.Refer || '',
                                id: (v.Type === 'Fees Received' && v.Id) ? v
                                    .Id : '',
                                type: v.Type || ''
                            });

                            rows.push([
                                '<span style="font-size:12px;color:var(--ab-muted)">' +
                                (v.Date || '—') + '</span>',
                                typeBadge(v.Type),
                                v.Particular || '—',
                                '<span style="font-size:12.5px;color:var(--ab-muted)">' +
                                (v['Payment Mode'] || '—') + '</span>',
                                drAmt ? '<span class="vv-dr">₹' + fmtAmt(
                                    drAmt) + '</span>' : '—',
                                crAmt ? '<span class="vv-cr">₹' + fmtAmt(
                                    crAmt) + '</span>' : '—'
                            ]);
                        });

                        rc.textContent = vouchers.length + ' record' + (vouchers.length !==
                            1 ? 's' : '');
                        rc.style.display = 'inline-flex';
                        emptyDiv.style.display = 'none';
                        tblOuter.style.display = '';
                    } else {
                        rc.style.display = 'none';
                        tblOuter.style.display = 'none';
                        emptyDiv.innerHTML =
                            '<i class="fa fa-inbox"></i><p>No vouchers found for the selected criteria.</p>';
                        emptyDiv.style.display = 'block';
                    }

                    /* Init DT once; subsequent calls just reload data */
                    if (!dtInstance) {
                        initDT();
                    }
                    dtInstance.clear().rows.add(rows).draw();
                },
                error: function() {
                    $btn.prop('disabled', false).html('<i class="fa fa-search"></i> Show');
                    toast('Error fetching vouchers. Please try again.', 'e');
                }
            });
        });

        /* ── Row click — use delegated event on the TABLE (survives DT redraws) ── */
        $('#voucherTable tbody').on('click', 'tr', function() {
            var $row = $(this);

            /* If row is already selected, deselect it */
            if ($row.hasClass('vv-selected')) {
                $row.removeClass('vv-selected');
                document.getElementById('refer').value = '';
                document.getElementById('vvRefRow').style.display = 'none';
                return;
            }

            /* Deselect any previously selected row */
            $('#voucherTable tbody tr.vv-selected').removeClass('vv-selected');
            $row.addClass('vv-selected');

            /* Get the DT row index to look up metadata */
            var dtRow = dtInstance ? dtInstance.row(this) : null;
            var idx = dtRow ? dtRow.index() : -1;
            var meta = (idx >= 0 && rowMeta[idx]) ? rowMeta[idx] : null;

            var refRow = document.getElementById('vvRefRow');
            if (meta) {
                var msg = meta.refer.trim() === '' ? 'No reference' : meta.refer;
                if (meta.type === 'Fees Received' && meta.id) msg += ' for Id: ' + meta.id;
                document.getElementById('refer').value = msg;
                refRow.style.display = 'flex';
            } else {
                document.getElementById('refer').value = '';
                refRow.style.display = 'none';
            }
        });

    })($); // end init
})(); // end waitForLibs
</script>


<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap');

:root {
  --ab-navy:   #0b1f3a;
  --ab-teal:   #0e7490;
  --ab-sky:    #e0f2fe;
  --ab-green:  #16a34a;
  --ab-red:    #dc2626;
  --ab-amber:  #d97706;
  --ab-text:   #1e293b;
  --ab-muted:  #64748b;
  --ab-border: #e2e8f0;
  --ab-white:  #ffffff;
  --ab-bg:     #f1f5f9;
  --ab-shadow: 0 1px 14px rgba(11,31,58,.08);
  --ab-radius: 12px;
}
*, *::before, *::after { box-sizing: border-box; }

.vv-wrap {
  font-family: 'DM Sans', sans-serif;
  background: var(--ab-bg);
  color: var(--ab-text);
  padding: 24px 20px 60px;
  min-height: 100%;
}

/* Top bar */
.vv-topbar {
  display: flex; align-items: flex-start;
  justify-content: space-between; flex-wrap: wrap;
  gap: 12px; margin-bottom: 22px;
}
.vv-page-title {
  font-family: 'Playfair Display', serif;
  font-size: 22px; font-weight: 700; color: var(--ab-navy);
  display: flex; align-items: center; gap: 10px; margin: 0 0 5px;
}
.vv-page-title i { color: var(--ab-teal); }
.vv-breadcrumb {
  display: flex; align-items: center; list-style: none;
  margin: 0; padding: 0; font-size: 12.5px; color: var(--ab-muted);
}
.vv-breadcrumb a { color: var(--ab-teal); text-decoration: none !important; font-weight: 500; }
.vv-breadcrumb li + li::before { content: '/'; margin: 0 7px; color: #cbd5e1; }
.vv-session-badge { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; }
.vv-badge-label { font-size: 10px; font-weight: 700; letter-spacing: .6px; text-transform: uppercase; color: var(--ab-muted); }
.vv-badge-val   { font-family: 'Playfair Display', serif; font-size: 17px; font-weight: 700; color: var(--ab-navy); line-height: 1; }

/* Card */
.vv-card {
  background: var(--ab-white);
  border-radius: var(--ab-radius);
  box-shadow: var(--ab-shadow);
  border: 1px solid var(--ab-border);
  overflow: hidden; margin-bottom: 16px;
}
.vv-card-head {
  display: flex; align-items: center; gap: 9px;
  padding: 13px 18px;
  border-bottom: 1px solid var(--ab-border);
  background: linear-gradient(90deg, var(--ab-sky) 0%, var(--ab-white) 100%);
}
.vv-card-head i { color: var(--ab-teal); font-size: 14px; }
.vv-card-head h3 { font-family: 'Playfair Display', serif; font-size: 14.5px; font-weight: 700; color: var(--ab-navy); margin: 0; }
.vv-card-head-right { margin-left: auto; display: flex; align-items: center; gap: 8px; }
.vv-card-body { padding: 18px; background: var(--ab-white); }

/* Labels + inputs */
.vv-label {
  font-size: 11px; font-weight: 700; letter-spacing: .5px;
  text-transform: uppercase; color: var(--ab-muted);
  display: block; margin-bottom: 5px;
}
.vv-input, .vv-select {
  height: 38px; padding: 0 10px;
  border: 1.5px solid var(--ab-border);
  border-radius: 8px;
  font-size: 13.5px; font-family: 'DM Sans', sans-serif;
  color: var(--ab-text); background: #fafcff;
  outline: none; width: 100%;
  transition: border-color .13s, box-shadow .13s;
}
.vv-select { padding-right: 32px; appearance: none; cursor: pointer; }
.vv-select-wrap { position: relative; }
.vv-select-wrap::after {
  content: '\f078'; font-family: 'Font Awesome 5 Free'; font-weight: 900;
  position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
  color: var(--ab-muted); font-size: 10px; pointer-events: none;
}
.vv-input:focus, .vv-select:focus {
  border-color: var(--ab-teal); box-shadow: 0 0 0 3px rgba(14,116,144,.10);
}
.vv-input:disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }

/* Filter row */
.vv-filter-row {
  display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;
}
.vv-fc { flex: 1; min-width: 160px; display: flex; flex-direction: column; }
.vv-fc-btn { flex: 0 0 auto; min-width: 130px; display: flex; flex-direction: column; }

/* Buttons */
.vv-btn {
  display: inline-flex; align-items: center; justify-content: center;
  gap: 7px; padding: 9px 18px;
  border-radius: 8px; border: none;
  font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 600;
  cursor: pointer; transition: opacity .13s, transform .1s;
  white-space: nowrap; text-decoration: none !important; height: 38px;
}
.vv-btn:hover:not(:disabled) { opacity: .85; transform: translateY(-1px); }
.vv-btn:disabled { opacity: .42; cursor: not-allowed; transform: none; pointer-events: none; }
.vv-btn-teal  { background: var(--ab-teal);  color: #fff; }
.vv-btn-navy  { background: var(--ab-navy);  color: #fff; }
.vv-btn-ghost { background: var(--ab-white); color: var(--ab-text); border: 1.5px solid var(--ab-border); }
.vv-btn-ghost:hover:not(:disabled) { border-color: var(--ab-teal); color: var(--ab-teal); background: var(--ab-sky); }

/* Record pill */
.vv-pill {
  display: none; font-size: 11px; font-weight: 700;
  padding: 3px 10px; border-radius: 20px;
  background: #f0fdf4; color: var(--ab-green); border: 1px solid #bbf7d0;
}

/* Table */
.vv-tbl-outer { overflow-x: auto; border-radius: 8px; border: 1px solid var(--ab-border); }
table.vv-tbl {
  width: 100%; border-collapse: collapse;
  font-size: 13px; font-family: 'DM Sans', sans-serif;
}
table.vv-tbl thead th {
  background: var(--ab-navy); color: rgba(255,255,255,.88);
  font-size: 11px; font-weight: 700; letter-spacing: .5px;
  text-transform: uppercase; padding: 11px 14px; white-space: nowrap; text-align: left;
}
table.vv-tbl thead th:first-child { border-radius: 8px 0 0 0; }
table.vv-tbl thead th:last-child  { border-radius: 0 8px 0 0; }
table.vv-tbl tbody tr { border-bottom: 1px solid var(--ab-border); background: var(--ab-white); transition: background .1s; cursor: pointer; }
table.vv-tbl tbody tr:last-child { border-bottom: none; }
table.vv-tbl tbody tr:hover { background: var(--ab-sky); }
table.vv-tbl tbody tr.vv-selected {
  background: linear-gradient(90deg, var(--ab-sky) 0%, #bae6fd 100%) !important;
  border-left: 3px solid var(--ab-teal);
}
table.vv-tbl tbody td { padding: 10px 14px; color: var(--ab-text); vertical-align: middle; }

/* Type badge */
.vv-type-badge {
  display: inline-flex; align-items: center;
  padding: 2px 9px; border-radius: 20px;
  font-size: 11px; font-weight: 600;
}
.vv-type-payment  { background: #fef2f2; color: var(--ab-red);   border: 1px solid #fecaca; }
.vv-type-receipt  { background: #f0fdf4; color: var(--ab-green); border: 1px solid #bbf7d0; }
.vv-type-journal  { background: #f0f9ff; color: var(--ab-teal);  border: 1px solid #bae6fd; }
.vv-type-contra   { background: #fefce8; color: var(--ab-amber); border: 1px solid #fde68a; }
.vv-type-other    { background: var(--ab-bg); color: var(--ab-muted); border: 1px solid var(--ab-border); }

/* Amount cells */
.vv-dr { color: var(--ab-red);   font-variant-numeric: tabular-nums; font-weight: 600; }
.vv-cr { color: var(--ab-green); font-variant-numeric: tabular-nums; font-weight: 600; }

/* Reference row */
.vv-ref-row {
  display: none; align-items: center; gap: 12px;
  padding: 12px 18px; background: var(--ab-bg);
  border-top: 1px solid var(--ab-border);
}
.vv-ref-row .vv-label { white-space: nowrap; margin: 0; min-width: 80px; }
.vv-ref-row .vv-input { flex: 1; }

/* Bottom action bar */
.vv-bottom-bar {
  display: flex; align-items: center; justify-content: flex-end; gap: 10px;
  padding: 14px 18px; border-top: 1px solid var(--ab-border); background: var(--ab-bg);
}

/* Empty state */
.vv-empty {
  padding: 52px 20px; text-align: center; background: var(--ab-white);
}
.vv-empty i { font-size: 28px; color: var(--ab-muted); opacity: .35; display: block; margin-bottom: 10px; }
.vv-empty p { font-size: 13px; color: var(--ab-muted); margin: 0; }
.vv-empty strong { color: var(--ab-teal); }

/* DataTables chrome */
.vv-wrap .dataTables_wrapper { padding: 13px 16px 16px; background: var(--ab-white); }
.vv-wrap .dataTables_wrapper .dataTables_filter input,
.vv-wrap .dataTables_wrapper .dataTables_length select {
  background: #fafcff !important; border: 1.5px solid var(--ab-border) !important;
  color: var(--ab-text) !important; border-radius: 7px !important; padding: 4px 10px !important;
  font-family: 'DM Sans', sans-serif !important;
}
.vv-wrap .dataTables_wrapper .dataTables_info,
.vv-wrap .dataTables_wrapper .dataTables_length,
.vv-wrap .dataTables_wrapper .dataTables_filter { color: var(--ab-muted) !important; font-size: 12px !important; }
.vv-wrap .dataTables_wrapper .dataTables_paginate .paginate_button {
  border-radius: 7px !important; color: var(--ab-muted) !important;
  font-size: 12px !important; border: none !important; background: transparent !important;
}
.vv-wrap .dataTables_wrapper .dataTables_paginate .paginate_button.current {
  background: var(--ab-teal) !important; color: #fff !important; border: none !important;
}
.vv-wrap .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
  background: var(--ab-sky) !important; color: var(--ab-teal) !important;
}
.vv-wrap div.dt-buttons .dt-button {
  background: var(--ab-white) !important; color: var(--ab-text) !important;
  border: 1.5px solid var(--ab-border) !important; border-radius: 7px !important;
  font-size: 11.5px !important; padding: 5px 12px !important;
}
.vv-wrap div.dt-buttons .dt-button:hover {
  background: var(--ab-sky) !important; border-color: var(--ab-teal) !important; color: var(--ab-teal) !important;
}

/* Toast */
.vv-toast-wrap {
  position: fixed; top: 20px; right: 20px; z-index: 99999;
  display: flex; flex-direction: column; gap: 8px; pointer-events: none;
}
.vv-toast {
  padding: 11px 16px; border-radius: 10px; color: #fff;
  font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 600;
  box-shadow: 0 4px 18px rgba(0,0,0,.2);
  display: flex; align-items: center; gap: 8px;
  animation: vv-tin .22s ease; max-width: 320px;
  pointer-events: auto; transition: opacity .3s;
}
.vv-toast.vv-hiding { opacity: 0; }
.vv-toast-e { background: var(--ab-red); }
@keyframes vv-tin { from{transform:translateX(20px);opacity:0} to{transform:translateX(0);opacity:1} }

@media (max-width: 640px) {
  .vv-filter-row { flex-direction: column; }
  .vv-fc, .vv-fc-btn { min-width: 100%; flex: unset; }
}
@media print {
  .vv-topbar, .vv-card:first-child, .vv-bottom-bar, .vv-toast-wrap { display: none !important; }
  .vv-card { box-shadow: none; }
}
</style>