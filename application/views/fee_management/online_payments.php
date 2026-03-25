<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<div class="content-wrapper">
  <!-- ── Top Bar ── -->
  <section class="content-header fm-topbar">
    <div class="fm-topbar-inner">
      <h1 class="fm-page-title"><i class="fa fa-credit-card"></i> Online Payments</h1>
      <ol class="breadcrumb fm-breadcrumb">
        <li><a href="<?= base_url('dashboard') ?>"><i class="fa fa-dashboard"></i> Dashboard</a></li>
        <li><a href="<?= base_url('fees') ?>">Fees</a></li>
        <li class="active">Online Payments</li>
      </ol>
    </div>
  </section>

  <section class="content fm-content">

    <!-- ── Stats Row ── -->
    <div class="fm-stats-row">
      <div class="fm-stat-card fm-stat-total">
        <div class="fm-stat-icon"><i class="fa fa-exchange"></i></div>
        <div class="fm-stat-body">
          <span class="fm-stat-value" id="statTotalTxn">0</span>
          <span class="fm-stat-label">Total Transactions</span>
        </div>
      </div>
      <div class="fm-stat-card fm-stat-success">
        <div class="fm-stat-icon"><i class="fa fa-check-circle"></i></div>
        <div class="fm-stat-body">
          <span class="fm-stat-value" id="statPaid">0</span>
          <span class="fm-stat-label">Successful</span>
        </div>
      </div>
      <div class="fm-stat-card fm-stat-pending">
        <div class="fm-stat-icon"><i class="fa fa-clock-o"></i></div>
        <div class="fm-stat-body">
          <span class="fm-stat-value" id="statCreated">0</span>
          <span class="fm-stat-label">Pending</span>
        </div>
      </div>
      <div class="fm-stat-card fm-stat-failed">
        <div class="fm-stat-icon"><i class="fa fa-times-circle"></i></div>
        <div class="fm-stat-body">
          <span class="fm-stat-value" id="statFailed">0</span>
          <span class="fm-stat-label">Failed</span>
        </div>
      </div>
      <div class="fm-stat-card fm-stat-amount">
        <div class="fm-stat-icon"><i class="fa fa-inr"></i></div>
        <div class="fm-stat-body">
          <span class="fm-stat-value" id="statAmount">&#8377;0</span>
          <span class="fm-stat-label">Total Amount</span>
        </div>
      </div>
    </div>

    <!-- ── Filter Bar ── -->
    <div class="fm-filter-bar">
      <div class="fm-filter-pills">
        <button class="fm-pill fm-pill-active" data-status="all" onclick="filterByStatus('all')">All</button>
        <button class="fm-pill" data-status="paid" onclick="filterByStatus('paid')">Paid</button>
        <button class="fm-pill" data-status="created" onclick="filterByStatus('created')">Created</button>
        <button class="fm-pill" data-status="failed" onclick="filterByStatus('failed')">Failed</button>
        <button class="fm-pill" data-status="refunded" onclick="filterByStatus('refunded')">Refunded</button>
      </div>
      <div class="fm-filter-dates">
        <div class="fm-date-group">
          <label for="dateFrom">From</label>
          <input type="date" id="dateFrom" class="fm-date-input" onchange="filterByDate()">
        </div>
        <div class="fm-date-group">
          <label for="dateTo">To</label>
          <input type="date" id="dateTo" class="fm-date-input" onchange="filterByDate()">
        </div>
      </div>
    </div>

    <!-- ── Payments Table ── -->
    <div class="fm-card">
      <div class="fm-card-header">
        <h3 class="fm-card-title"><i class="fa fa-table"></i> Payment Transactions</h3>
        <button class="fm-btn fm-btn-sm fm-btn-outline" onclick="loadPayments()" title="Refresh">
          <i class="fa fa-refresh"></i>
        </button>
      </div>
      <div class="fm-card-body">
        <div class="fm-table-wrap">
          <table class="fm-table" id="paymentsTable">
            <thead>
              <tr>
                <th width="40">S.No</th>
                <th width="90">Date</th>
                <th>Order ID</th>
                <th>Student Name</th>
                <th width="80">Class</th>
                <th width="90">Amount</th>
                <th>Fee Months</th>
                <th width="80">Gateway</th>
                <th>Payment ID</th>
                <th width="80">Status</th>
                <th width="80">Actions</th>
              </tr>
            </thead>
            <tbody id="paymentsBody">
              <!-- AJAX loaded -->
            </tbody>
          </table>
        </div>

        <!-- Empty State -->
        <div class="fm-empty-state" id="emptyState" style="display:none;">
          <div class="fm-empty-icon"><i class="fa fa-credit-card"></i></div>
          <h4>No payment transactions found</h4>
          <p>Online payment records will appear here once students initiate payments.</p>
        </div>

        <!-- Loading -->
        <div class="fm-loading" id="tableLoading">
          <div class="fm-spinner"></div>
          <span>Loading payments...</span>
        </div>
      </div>
    </div>

    <!-- ── Create Test Payment (only in test mode) ── -->
    <?php if (!empty($gateway_mode) && $gateway_mode === 'test'): ?>
    <div class="fm-card fm-card-test">
      <div class="fm-card-header">
        <h3 class="fm-card-title"><i class="fa fa-flask"></i> Create Test Payment</h3>
        <span class="fm-badge fm-badge-gold">TEST MODE</span>
      </div>
      <div class="fm-card-body">
        <form id="testPaymentForm" onsubmit="createTestOrder(event)">
          <div class="row">
            <div class="col-md-4">
              <div class="fm-form-group">
                <label class="fm-label">Student ID</label>
                <div class="fm-input-group">
                  <input type="text" id="testStudentId" class="fm-input" placeholder="Enter Student ID" required>
                  <button type="button" class="fm-btn fm-btn-sm fm-btn-teal" onclick="lookupStudent()">
                    <i class="fa fa-search"></i>
                  </button>
                </div>
                <div class="fm-student-info" id="testStudentInfo" style="display:none;"></div>
              </div>
            </div>
            <div class="col-md-3">
              <div class="fm-form-group">
                <label class="fm-label">Amount (&#8377;)</label>
                <input type="number" id="testAmount" class="fm-input" placeholder="0.00" min="1" step="0.01" required>
              </div>
            </div>
            <div class="col-md-5">
              <div class="fm-form-group">
                <label class="fm-label">Fee Months</label>
                <div class="fm-month-grid">
                  <?php
                  $months = ['April','May','June','July','August','September','October','November','December','January','February','March'];
                  foreach ($months as $m):
                  ?>
                  <label class="fm-month-check">
                    <input type="checkbox" name="fee_months[]" value="<?= $m ?>">
                    <span><?= substr($m, 0, 3) ?></span>
                  </label>
                  <?php endforeach; ?>
                </div>
              </div>
            </div>
          </div>
          <div class="fm-form-actions">
            <button type="submit" class="fm-btn fm-btn-teal" id="btnCreateOrder">
              <i class="fa fa-plus-circle"></i> Create Order
            </button>
          </div>
          <div class="fm-order-response" id="orderResponse" style="display:none;">
            <h5>Order Response</h5>
            <pre id="orderResponseBody"></pre>
          </div>
        </form>
      </div>
    </div>
    <?php endif; ?>

  </section>
</div>

<!-- ── Payment Details Modal ── -->
<div class="modal fade" id="detailsModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-lg" role="document">
    <div class="modal-content fm-modal">
      <div class="modal-header fm-modal-header">
        <button type="button" class="close fm-modal-close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-file-text-o"></i> Payment Details</h4>
      </div>
      <div class="modal-body fm-modal-body">
        <div class="fm-detail-grid">
          <div class="fm-detail-row">
            <div class="fm-detail-cell">
              <span class="fm-detail-label">Order ID</span>
              <span class="fm-detail-value" id="mdOrderId">-</span>
              <button class="fm-copy-btn" onclick="copyText(document.getElementById('mdOrderId').textContent)" title="Copy"><i class="fa fa-copy"></i></button>
            </div>
            <div class="fm-detail-cell">
              <span class="fm-detail-label">Payment ID</span>
              <span class="fm-detail-value" id="mdPaymentId">-</span>
              <button class="fm-copy-btn" onclick="copyText(document.getElementById('mdPaymentId').textContent)" title="Copy"><i class="fa fa-copy"></i></button>
            </div>
          </div>
          <div class="fm-detail-row">
            <div class="fm-detail-cell">
              <span class="fm-detail-label">Student Name</span>
              <span class="fm-detail-value" id="mdStudentName">-</span>
            </div>
            <div class="fm-detail-cell">
              <span class="fm-detail-label">Student ID</span>
              <span class="fm-detail-value" id="mdStudentId">-</span>
            </div>
          </div>
          <div class="fm-detail-row">
            <div class="fm-detail-cell">
              <span class="fm-detail-label">Class</span>
              <span class="fm-detail-value" id="mdClass">-</span>
            </div>
            <div class="fm-detail-cell">
              <span class="fm-detail-label">Amount</span>
              <span class="fm-detail-value fm-detail-amount" id="mdAmount">-</span>
            </div>
          </div>
          <div class="fm-detail-row">
            <div class="fm-detail-cell">
              <span class="fm-detail-label">Fee Months</span>
              <span class="fm-detail-value" id="mdFeeMonths">-</span>
            </div>
            <div class="fm-detail-cell">
              <span class="fm-detail-label">Gateway</span>
              <span class="fm-detail-value" id="mdGateway">-</span>
            </div>
          </div>
          <div class="fm-detail-row">
            <div class="fm-detail-cell">
              <span class="fm-detail-label">Status</span>
              <span class="fm-detail-value" id="mdStatus">-</span>
            </div>
            <div class="fm-detail-cell">
              <span class="fm-detail-label">Method</span>
              <span class="fm-detail-value" id="mdMethod">-</span>
            </div>
          </div>
          <div class="fm-detail-row fm-detail-row-full">
            <div class="fm-detail-cell">
              <span class="fm-detail-label">Notes</span>
              <span class="fm-detail-value" id="mdNotes">-</span>
            </div>
          </div>
        </div>

        <!-- Transaction Timeline -->
        <div class="fm-timeline-section">
          <h5 class="fm-timeline-title">Transaction Timeline</h5>
          <div class="fm-timeline" id="mdTimeline">
            <!-- Populated by JS -->
          </div>
        </div>
      </div>
      <div class="modal-footer fm-modal-footer">
        <button type="button" class="fm-btn fm-btn-outline" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Refund Confirmation Modal ── -->
<div class="modal fade" id="refundModal" tabindex="-1" role="dialog">
  <div class="modal-dialog modal-sm" role="document">
    <div class="modal-content fm-modal">
      <div class="modal-header fm-modal-header fm-modal-header-warn">
        <button type="button" class="close fm-modal-close" data-dismiss="modal">&times;</button>
        <h4 class="modal-title"><i class="fa fa-undo"></i> Confirm Refund</h4>
      </div>
      <div class="modal-body fm-modal-body">
        <p class="fm-refund-msg">Are you sure you want to initiate a refund for this payment?</p>
        <div class="fm-refund-info">
          <div><strong>Order ID:</strong> <span id="rfOrderId">-</span></div>
          <div><strong>Student:</strong> <span id="rfStudent">-</span></div>
          <div><strong>Amount:</strong> <span id="rfAmount">-</span></div>
        </div>
        <div class="fm-form-group" style="margin-top:12px;">
          <label class="fm-label">Reason (optional)</label>
          <textarea id="rfReason" class="fm-input fm-textarea" rows="2" placeholder="Refund reason..."></textarea>
        </div>
      </div>
      <div class="modal-footer fm-modal-footer">
        <button type="button" class="fm-btn fm-btn-outline" data-dismiss="modal">Cancel</button>
        <button type="button" class="fm-btn fm-btn-red" id="btnConfirmRefund" onclick="confirmRefund()">
          <i class="fa fa-undo"></i> Initiate Refund
        </button>
      </div>
    </div>
  </div>
</div>

<!-- Toast -->
<div id="fmToast" class="fm-toast"></div>


<script>
document.addEventListener('DOMContentLoaded', function() {
  'use strict';

  var BASE = '<?= base_url() ?>';
  var CSRF_NAME = document.querySelector('meta[name="csrf-name"]').content;
  var CSRF_HASH = document.querySelector('meta[name="csrf-token"]').content;
  var paymentsCache = [];
  var currentFilter = 'all';
  var refundTargetId = null;

  /* ── Load Payments ── */
  window.loadPayments = function(status, dateFrom, dateTo) {
    status = status || currentFilter;
    dateFrom = dateFrom || document.getElementById('dateFrom').value;
    dateTo = dateTo || document.getElementById('dateTo').value;

    var tbody = document.getElementById('paymentsBody');
    var loading = document.getElementById('tableLoading');
    var empty = document.getElementById('emptyState');

    tbody.innerHTML = '';
    loading.style.display = 'flex';
    empty.style.display = 'none';

    var params = {};
    params[CSRF_NAME] = CSRF_HASH;
    if (status && status !== 'all') params.status = status;
    if (dateFrom) params.date_from = dateFrom;
    if (dateTo) params.date_to = dateTo;

    $.ajax({
      url: BASE + 'fee_management/fetch_online_payments',
      type: 'POST',
      data: params,
      dataType: 'json',
      success: function(res) {
        loading.style.display = 'none';
        if (res.csrf_hash) CSRF_HASH = res.csrf_hash;

        var payments = res.payments || [];
        paymentsCache = payments;
        updateStats(payments, res.stats || null);

        if (!payments.length) {
          empty.style.display = 'flex';
          return;
        }

        var html = '';
        for (var i = 0; i < payments.length; i++) {
          var p = payments[i];
          html += renderRow(i + 1, p);
        }
        tbody.innerHTML = html;
      },
      error: function() {
        loading.style.display = 'none';
        empty.style.display = 'flex';
        showToast('Failed to load payments', 'error');
      }
    });
  };

  /* ── Render Table Row ── */
  function renderRow(sno, p) {
    var statusClass = 'fm-badge-gray';
    var statusLabel = p.status || 'created';
    var sl = statusLabel.toLowerCase();

    if (sl === 'paid') statusClass = 'fm-badge-green';
    else if (sl === 'failed') statusClass = 'fm-badge-red';
    else if (sl === 'refunded') statusClass = 'fm-badge-purple';
    else statusClass = 'fm-badge-gray';

    var months = '';
    if (p.fee_months) {
      if (Array.isArray(p.fee_months)) months = p.fee_months.join(', ');
      else months = p.fee_months;
    }

    var actions = '<button class="fm-action-btn" onclick="showDetails(\'' + escHtml(p.id || p.order_id) + '\')" title="View Details"><i class="fa fa-eye"></i></button>';
    if (sl === 'paid') {
      actions += ' <button class="fm-action-btn fm-action-refund" onclick="initiateRefund(\'' + escHtml(p.id || p.order_id) + '\')" title="Refund"><i class="fa fa-undo"></i></button>';
    }

    return '<tr>' +
      '<td>' + sno + '</td>' +
      '<td>' + escHtml(p.date || '-') + '</td>' +
      '<td class="fm-mono">' + escHtml(p.order_id || '-') + '</td>' +
      '<td>' + escHtml(p.student_name || '-') + '</td>' +
      '<td>' + escHtml(p.class || '-') + '</td>' +
      '<td class="fm-amount">&#8377;' + formatNum(p.amount || 0) + '</td>' +
      '<td>' + escHtml(months || '-') + '</td>' +
      '<td>' + escHtml(p.gateway || '-') + '</td>' +
      '<td class="fm-mono">' + escHtml(p.payment_id || '-') + '</td>' +
      '<td><span class="fm-badge ' + statusClass + '">' + escHtml(capitalize(statusLabel)) + '</span></td>' +
      '<td class="fm-actions">' + actions + '</td>' +
      '</tr>';
  }

  /* ── Update Stats ── */
  function updateStats(payments, stats) {
    if (stats) {
      document.getElementById('statTotalTxn').textContent = stats.total || payments.length;
      document.getElementById('statPaid').textContent = stats.paid || 0;
      document.getElementById('statCreated').textContent = stats.created || 0;
      document.getElementById('statFailed').textContent = stats.failed || 0;
      document.getElementById('statAmount').textContent = '\u20B9' + formatNum(stats.total_amount || 0);
      return;
    }

    var paid = 0, created = 0, failed = 0, totalAmt = 0;
    for (var i = 0; i < payments.length; i++) {
      var s = (payments[i].status || '').toLowerCase();
      if (s === 'paid') { paid++; totalAmt += parseFloat(payments[i].amount) || 0; }
      else if (s === 'failed') failed++;
      else if (s === 'created') created++;
    }
    document.getElementById('statTotalTxn').textContent = payments.length;
    document.getElementById('statPaid').textContent = paid;
    document.getElementById('statCreated').textContent = created;
    document.getElementById('statFailed').textContent = failed;
    document.getElementById('statAmount').textContent = '\u20B9' + formatNum(totalAmt);
  }

  /* ── Filter by Status ── */
  window.filterByStatus = function(s) {
    currentFilter = s;
    var pills = document.querySelectorAll('.fm-pill');
    for (var i = 0; i < pills.length; i++) {
      pills[i].classList.toggle('fm-pill-active', pills[i].getAttribute('data-status') === s);
    }
    loadPayments(s);
  };

  /* ── Filter by Date ── */
  window.filterByDate = function() {
    loadPayments(currentFilter);
  };

  /* ── Show Details Modal ── */
  window.showDetails = function(id) {
    var p = findPayment(id);
    if (!p) { showToast('Payment not found', 'error'); return; }

    document.getElementById('mdOrderId').textContent = p.order_id || '-';
    document.getElementById('mdPaymentId').textContent = p.payment_id || '-';
    document.getElementById('mdStudentName').textContent = p.student_name || '-';
    document.getElementById('mdStudentId').textContent = p.student_id || '-';
    document.getElementById('mdClass').textContent = p.class || '-';
    document.getElementById('mdAmount').textContent = '\u20B9' + formatNum(p.amount || 0);
    document.getElementById('mdGateway').textContent = p.gateway || '-';
    document.getElementById('mdMethod').textContent = p.method || '-';
    document.getElementById('mdNotes').textContent = p.notes || 'None';

    var months = '';
    if (p.fee_months) {
      months = Array.isArray(p.fee_months) ? p.fee_months.join(', ') : p.fee_months;
    }
    document.getElementById('mdFeeMonths').textContent = months || '-';

    var statusHtml = '<span class="fm-badge ' + getStatusClass(p.status) + '">' + capitalize(p.status || 'created') + '</span>';
    document.getElementById('mdStatus').innerHTML = statusHtml;

    // Timeline
    var timeline = document.getElementById('mdTimeline');
    var tlHtml = '';
    tlHtml += buildTimelineItem('Order Created', p.created_at, 'fa-plus-circle', true);

    var sl = (p.status || '').toLowerCase();
    if (sl === 'paid') {
      tlHtml += buildTimelineItem('Payment Successful', p.paid_at || p.updated_at, 'fa-check-circle', true, 'fm-tl-success');
    } else if (sl === 'failed') {
      tlHtml += buildTimelineItem('Payment Failed', p.failed_at || p.updated_at, 'fa-times-circle', true, 'fm-tl-failed');
    } else if (sl === 'refunded') {
      tlHtml += buildTimelineItem('Payment Successful', p.paid_at, 'fa-check-circle', true, 'fm-tl-success');
      tlHtml += buildTimelineItem('Refund Initiated', p.refunded_at || p.updated_at, 'fa-undo', true, 'fm-tl-refund');
    } else {
      tlHtml += buildTimelineItem('Awaiting Payment', null, 'fa-hourglass-half', false, 'fm-tl-pending');
    }

    timeline.innerHTML = tlHtml;
    $('#detailsModal').modal('show');
  };

  function buildTimelineItem(label, timestamp, icon, completed, extraClass) {
    extraClass = extraClass || '';
    var cls = 'fm-tl-item' + (completed ? ' fm-tl-done' : '') + (extraClass ? ' ' + extraClass : '');
    var ts = timestamp ? formatTimestamp(timestamp) : 'Pending';
    return '<div class="' + cls + '">' +
      '<div class="fm-tl-dot"><i class="fa ' + icon + '"></i></div>' +
      '<div class="fm-tl-content">' +
        '<span class="fm-tl-label">' + label + '</span>' +
        '<span class="fm-tl-time">' + ts + '</span>' +
      '</div>' +
    '</div>';
  }

  /* ── Initiate Refund ── */
  window.initiateRefund = function(id) {
    var p = findPayment(id);
    if (!p) return;
    refundTargetId = id;

    document.getElementById('rfOrderId').textContent = p.order_id || '-';
    document.getElementById('rfStudent').textContent = p.student_name || '-';
    document.getElementById('rfAmount').textContent = '\u20B9' + formatNum(p.amount || 0);
    document.getElementById('rfReason').value = '';

    $('#refundModal').modal('show');
  };

  window.confirmRefund = function() {
    if (!refundTargetId) return;

    var btn = document.getElementById('btnConfirmRefund');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Processing...';

    var params = {};
    params[CSRF_NAME] = CSRF_HASH;
    params.id = refundTargetId;
    params.reason = document.getElementById('rfReason').value.trim();

    $.ajax({
      url: BASE + 'fee_management/refund_online_payment',
      type: 'POST',
      data: params,
      dataType: 'json',
      success: function(res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-undo"></i> Initiate Refund';
        if (res.csrf_hash) CSRF_HASH = res.csrf_hash;

        if (res.status === 'success') {
          $('#refundModal').modal('hide');
          showToast('Refund initiated successfully', 'success');
          loadPayments();
        } else {
          showToast(res.message || 'Refund failed', 'error');
        }
      },
      error: function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-undo"></i> Initiate Refund';
        showToast('Network error', 'error');
      }
    });
  };

  /* ── Create Test Order ── */
  window.createTestOrder = function(e) {
    e.preventDefault();

    var studentId = document.getElementById('testStudentId').value.trim();
    var amount = document.getElementById('testAmount').value;
    var checked = document.querySelectorAll('#testPaymentForm input[name="fee_months[]"]:checked');
    var months = [];
    for (var i = 0; i < checked.length; i++) months.push(checked[i].value);

    if (!studentId || !amount || !months.length) {
      showToast('Please fill all fields and select at least one month', 'error');
      return;
    }

    var btn = document.getElementById('btnCreateOrder');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Creating...';

    var params = {};
    params[CSRF_NAME] = CSRF_HASH;
    params.student_id = studentId;
    params.amount = amount;
    params.fee_months = months;

    $.ajax({
      url: BASE + 'fee_management/create_payment_order',
      type: 'POST',
      data: params,
      dataType: 'json',
      success: function(res) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-plus-circle"></i> Create Order';
        if (res.csrf_hash) CSRF_HASH = res.csrf_hash;

        var respDiv = document.getElementById('orderResponse');
        var respBody = document.getElementById('orderResponseBody');
        respDiv.style.display = 'block';
        respBody.textContent = JSON.stringify(res, null, 2);

        if (res.status === 'success') {
          showToast('Test order created', 'success');
          loadPayments();
        } else {
          showToast(res.message || 'Order creation failed', 'error');
        }
      },
      error: function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-plus-circle"></i> Create Order';
        showToast('Network error', 'error');
      }
    });
  };

  /* ── Lookup Student ── */
  window.lookupStudent = function() {
    var sid = document.getElementById('testStudentId').value.trim();
    if (!sid) return;

    var info = document.getElementById('testStudentInfo');
    info.style.display = 'none';
    info.textContent = 'Looking up...';
    info.style.display = 'block';

    var params = {};
    params[CSRF_NAME] = CSRF_HASH;
    params.user_id = sid;

    $.ajax({
      url: BASE + 'fees/lookup_student',
      type: 'POST',
      data: params,
      dataType: 'json',
      success: function(res) {
        if (res.csrf_hash) CSRF_HASH = res.csrf_hash;
        if (res.name) {
          info.innerHTML = '<i class="fa fa-user"></i> ' + escHtml(res.name) + ' <span class="fm-text-muted">(' + escHtml(res.class || '') + ')</span>';
        } else {
          info.innerHTML = '<span class="fm-text-red">Student not found</span>';
        }
      },
      error: function() {
        info.innerHTML = '<span class="fm-text-red">Lookup failed</span>';
      }
    });
  };

  /* ── Copy Text ── */
  window.copyText = function(t) {
    if (!t || t === '-') return;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(t).then(function() {
        showToast('Copied to clipboard', 'success');
      });
    } else {
      var ta = document.createElement('textarea');
      ta.value = t;
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
      showToast('Copied to clipboard', 'success');
    }
  };

  /* ── Toast ── */
  window.showToast = function(msg, type) {
    var toast = document.getElementById('fmToast');
    toast.textContent = msg;
    toast.className = 'fm-toast fm-toast-show';
    if (type === 'error') toast.classList.add('fm-toast-error');
    else if (type === 'success') toast.classList.add('fm-toast-success');

    setTimeout(function() { toast.className = 'fm-toast'; }, 3000);
  };

  /* ── Helpers ── */
  function findPayment(id) {
    for (var i = 0; i < paymentsCache.length; i++) {
      if (paymentsCache[i].id === id || paymentsCache[i].order_id === id) return paymentsCache[i];
    }
    return null;
  }

  function getStatusClass(s) {
    s = (s || '').toLowerCase();
    if (s === 'paid') return 'fm-badge-green';
    if (s === 'failed') return 'fm-badge-red';
    if (s === 'refunded') return 'fm-badge-purple';
    return 'fm-badge-gray';
  }

  function escHtml(s) {
    if (!s) return '';
    var d = document.createElement('div');
    d.appendChild(document.createTextNode(s));
    return d.innerHTML;
  }

  function capitalize(s) {
    return s ? s.charAt(0).toUpperCase() + s.slice(1).toLowerCase() : '';
  }

  function formatNum(n) {
    n = parseFloat(n) || 0;
    return n.toLocaleString('en-IN', { minimumFractionDigits: 0, maximumFractionDigits: 2 });
  }

  function formatTimestamp(ts) {
    if (!ts) return '-';
    var d = new Date(ts);
    if (isNaN(d.getTime())) return ts;
    return d.toLocaleDateString('en-IN', { day: '2-digit', month: 'short', year: 'numeric' }) +
      ' ' + d.toLocaleTimeString('en-IN', { hour: '2-digit', minute: '2-digit' });
  }

  /* ── Initial Load ── */
  loadPayments();
});
</script>

<style>
/* ── Online Payments — fm-* prefix ── */
@import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&family=Fraunces:wght@600;700&display=swap');

:root {
  --fm-navy: var(--t1, #0f1f3d);
  --fm-navy-light: var(--t2, #1a2d52);
  --fm-teal: var(--gold, #0f766e);
  --fm-teal-light: var(--gold2, #0d6b63);
  --fm-teal-dim: var(--gold-dim, rgba(13,115,119,.10));
  --fm-sky: var(--gold-dim, rgba(15,118,110,.10));
  --fm-gold: #d97706;
  --fm-gold-dim: rgba(217,119,6,.10);
  --fm-red: #E05C6F;
  --fm-red-dim: rgba(224,92,111,.08);
  --fm-green: #15803d;
  --fm-green-dim: rgba(21,128,61,.08);
  --fm-purple: #7c3aed;
  --fm-purple-dim: rgba(124,58,237,.08);
  --fm-gray: var(--t3, #64748b);
  --fm-gray-dim: rgba(100,116,139,.08);
  --fm-bg: var(--bg, #f5f7fa);
  --fm-white: var(--bg2, #ffffff);
  --fm-border: var(--border, #e2e8f0);
  --fm-text: var(--t1, #1e293b);
  --fm-text-secondary: var(--t3, #64748b);
  --fm-shadow: var(--sh, 0 1px 3px rgba(0,0,0,.06));
  --fm-shadow-md: 0 4px 12px rgba(0,0,0,.08);
  --fm-radius: var(--r-sm, 8px);
  --fm-radius-sm: 5px;
  --fm-font: 'Plus Jakarta Sans', var(--font-b, sans-serif);
  --fm-font-display: 'Fraunces', Georgia, serif;
}

/* ── Top Bar ── */
.fm-topbar { padding: 12px 20px; background: var(--fm-white); border-bottom: 1px solid var(--fm-border); }
.fm-topbar-inner { display: flex; align-items: center; justify-content: space-between; }
.fm-page-title { font-family: var(--fm-font); font-size: 1.3rem; font-weight: 700; color: var(--fm-navy); margin: 0; }
.fm-page-title i { color: var(--fm-teal); margin-right: 8px; }
.fm-breadcrumb { background: none; padding: 0; margin: 0; font-size: 12px; }
.fm-breadcrumb li a { color: var(--fm-teal); }
.fm-breadcrumb > .active { color: var(--fm-text-secondary); }

/* ── Content ── */
.fm-content { padding: 16px 20px; font-family: var(--fm-font); }

/* ── Stats Row ── */
.fm-stats-row { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-bottom: 16px; }
.fm-stat-card {
  background: var(--fm-white); border: 1px solid var(--fm-border); border-radius: var(--fm-radius);
  padding: 14px 16px; display: flex; align-items: center; gap: 12px;
  box-shadow: var(--fm-shadow); transition: transform .15s, box-shadow .15s;
}
.fm-stat-card:hover { transform: translateY(-1px); box-shadow: var(--fm-shadow-md); }
.fm-stat-icon {
  width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center;
  font-size: 16px; flex-shrink: 0;
}
.fm-stat-total .fm-stat-icon { background: var(--fm-teal-dim); color: var(--fm-teal); }
.fm-stat-success .fm-stat-icon { background: var(--fm-green-dim); color: var(--fm-green); }
.fm-stat-pending .fm-stat-icon { background: var(--fm-gold-dim); color: var(--fm-gold); }
.fm-stat-failed .fm-stat-icon { background: var(--fm-red-dim); color: var(--fm-red); }
.fm-stat-amount .fm-stat-icon { background: var(--fm-navy); color: #fff; }
.fm-stat-body { display: flex; flex-direction: column; }
.fm-stat-value { font-size: 1.25rem; font-weight: 700; color: var(--fm-navy); line-height: 1.2; font-family: var(--fm-font-display); }
.fm-stat-label { font-size: 12.5px; color: var(--fm-text-secondary); font-weight: 500; letter-spacing: .3px; text-transform: uppercase; }

/* ── Filter Bar ── */
.fm-filter-bar {
  display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 12px;
  background: var(--fm-white); border: 1px solid var(--fm-border); border-radius: var(--fm-radius);
  padding: 10px 16px; margin-bottom: 16px; box-shadow: var(--fm-shadow);
}
.fm-filter-pills { display: flex; gap: 6px; flex-wrap: wrap; }
.fm-pill {
  padding: 5px 14px; border-radius: 20px; border: 1px solid var(--fm-border);
  background: var(--fm-white); color: var(--fm-text-secondary); font-size: 12px; font-weight: 600;
  cursor: pointer; transition: all .15s; font-family: var(--fm-font); outline: none;
}
.fm-pill:hover { border-color: var(--fm-teal); color: var(--fm-teal); }
.fm-pill-active { background: var(--fm-teal); color: #fff; border-color: var(--fm-teal); }
.fm-pill-active:hover { background: var(--fm-teal-light); color: #fff; }
.fm-filter-dates { display: flex; align-items: center; gap: 10px; }
.fm-date-group { display: flex; align-items: center; gap: 5px; }
.fm-date-group label { font-size: 12.5px; font-weight: 600; color: var(--fm-text-secondary); margin: 0; }
.fm-date-input {
  padding: 5px 10px; border: 1px solid var(--fm-border); border-radius: var(--fm-radius-sm);
  font-size: 12px; font-family: var(--fm-font); color: var(--fm-text); outline: none; transition: border-color .15s;
}
.fm-date-input:focus { border-color: var(--fm-teal); }

/* ── Card ── */
.fm-card {
  background: var(--fm-white); border: 1px solid var(--fm-border); border-radius: var(--fm-radius);
  box-shadow: var(--fm-shadow); margin-bottom: 16px; overflow: hidden;
}
.fm-card-header {
  padding: 12px 16px; border-bottom: 1px solid var(--fm-border);
  display: flex; align-items: center; justify-content: space-between;
}
.fm-card-title { font-size: 14px; font-weight: 700; color: var(--fm-navy); margin: 0; }
.fm-card-title i { color: var(--fm-teal); margin-right: 6px; }
.fm-card-body { padding: 0; }
.fm-card-test { border-left: 3px solid var(--fm-gold); }
.fm-card-test .fm-card-body { padding: 16px; }

/* ── Table ── */
.fm-table-wrap { overflow-x: auto; }
.fm-table { width: 100%; border-collapse: collapse; font-size: .8rem; }
.fm-table thead th {
  padding: 10px 12px; background: var(--fm-sky); color: var(--fm-navy); font-weight: 600;
  text-transform: uppercase; font-size: .7rem; letter-spacing: .4px; border-bottom: 2px solid var(--fm-border);
  white-space: nowrap; text-align: left;
}
.fm-table tbody td {
  padding: 9px 12px; border-bottom: 1px solid var(--fm-border); color: var(--fm-text);
  vertical-align: middle;
}
.fm-table tbody tr:hover { background: rgba(13,115,119,.02); }
.fm-mono { font-family: 'JetBrains Mono', monospace; font-size: 12.5px; letter-spacing: -.2px; }
.fm-amount { font-weight: 600; color: var(--fm-navy); white-space: nowrap; }

/* ── Badges ── */
.fm-badge {
  display: inline-block; padding: 3px 10px; border-radius: 12px;
  font-size: .68rem; font-weight: 600; letter-spacing: .2px; white-space: nowrap;
}
.fm-badge-green { background: var(--fm-green-dim); color: var(--fm-green); }
.fm-badge-red { background: var(--fm-red-dim); color: var(--fm-red); }
.fm-badge-gray { background: var(--fm-gray-dim); color: var(--fm-gray); }
.fm-badge-purple { background: var(--fm-purple-dim); color: var(--fm-purple); }
.fm-badge-gold { background: var(--fm-gold-dim); color: var(--fm-gold); }

/* ── Action Buttons ── */
.fm-actions { white-space: nowrap; }
.fm-action-btn {
  width: 28px; height: 28px; border-radius: 6px; border: 1px solid var(--fm-border);
  background: var(--fm-white); color: var(--fm-teal); cursor: pointer;
  display: inline-flex; align-items: center; justify-content: center;
  font-size: 13px; transition: all .15s; outline: none; padding: 0;
}
.fm-action-btn:hover { background: var(--fm-teal); color: #fff; border-color: var(--fm-teal); }
.fm-action-refund { color: var(--fm-red); }
.fm-action-refund:hover { background: var(--fm-red); color: #fff; border-color: var(--fm-red); }

/* ── Empty State ── */
.fm-empty-state {
  display: flex; flex-direction: column; align-items: center; justify-content: center;
  padding: 48px 20px; color: var(--fm-text-secondary); text-align: center;
}
.fm-empty-icon { font-size: 48px; color: var(--fm-border); margin-bottom: 12px; }
.fm-empty-state h4 { font-size: 15px; font-weight: 600; color: var(--fm-navy); margin: 0 0 6px; }
.fm-empty-state p { font-size: 13px; margin: 0; }

/* ── Loading ── */
.fm-loading {
  display: flex; align-items: center; justify-content: center; gap: 10px;
  padding: 40px 20px; color: var(--fm-text-secondary); font-size: 13px;
}
.fm-spinner {
  width: 20px; height: 20px; border: 2px solid var(--fm-border);
  border-top-color: var(--fm-teal); border-radius: 50%;
  animation: fmSpin .6s linear infinite;
}
@keyframes fmSpin { to { transform: rotate(360deg); } }

/* ── Buttons ── */
.fm-btn {
  display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px;
  border-radius: var(--fm-radius-sm); font-size: 13px; font-weight: 600; font-family: var(--fm-font);
  border: none; cursor: pointer; transition: all .15s; outline: none; text-decoration: none;
}
.fm-btn-sm { padding: 5px 10px; font-size: 12px; }
.fm-btn-teal { background: var(--fm-teal); color: #fff; }
.fm-btn-teal:hover { background: var(--fm-teal-light); color: #fff; }
.fm-btn-red { background: var(--fm-red); color: #fff; }
.fm-btn-red:hover { background: #c53030; color: #fff; }
.fm-btn-outline { background: transparent; border: 1px solid var(--fm-border); color: var(--fm-text-secondary); }
.fm-btn-outline:hover { border-color: var(--fm-teal); color: var(--fm-teal); }

/* ── Forms (Test Payment) ── */
.fm-form-group { margin-bottom: 14px; }
.fm-label { display: block; font-size: .73rem; font-weight: 600; color: var(--fm-text-secondary); margin-bottom: 5px; text-transform: uppercase; letter-spacing: .3px; }
.fm-input {
  width: 100%; padding: 8px 12px; border: 1px solid var(--fm-border); border-radius: var(--fm-radius-sm);
  font-size: 13px; font-family: var(--fm-font); color: var(--fm-text); outline: none; transition: border-color .15s;
  background: var(--fm-white);
}
.fm-input:focus { border-color: var(--fm-teal); box-shadow: 0 0 0 3px var(--fm-teal-dim); }
.fm-textarea { resize: vertical; }
.fm-input-group { display: flex; gap: 6px; }
.fm-input-group .fm-input { flex: 1; }
.fm-form-actions { margin-top: 4px; }

/* ── Month Grid ── */
.fm-month-grid { display: grid; grid-template-columns: repeat(6, 1fr); gap: 4px; }
.fm-month-check {
  display: flex; align-items: center; justify-content: center;
  position: relative; cursor: pointer;
}
.fm-month-check input { position: absolute; opacity: 0; width: 0; height: 0; }
.fm-month-check span {
  display: block; padding: 5px 8px; border-radius: 4px; font-size: 12.5px; font-weight: 600;
  border: 1px solid var(--fm-border); color: var(--fm-text-secondary); transition: all .15s;
  text-align: center; width: 100%; user-select: none;
}
.fm-month-check input:checked + span { background: var(--fm-teal); color: #fff; border-color: var(--fm-teal); }
.fm-month-check:hover span { border-color: var(--fm-teal); }

/* ── Student Info ── */
.fm-student-info {
  margin-top: 6px; padding: 6px 10px; border-radius: var(--fm-radius-sm);
  background: var(--fm-sky); font-size: 12px; color: var(--fm-navy);
}
.fm-text-muted { color: var(--fm-text-secondary); }
.fm-text-red { color: var(--fm-red); }

/* ── Order Response ── */
.fm-order-response {
  margin-top: 14px; padding: 12px; background: var(--fm-sky); border-radius: var(--fm-radius-sm);
  border: 1px solid var(--fm-border);
}
.fm-order-response h5 { font-size: 12px; font-weight: 700; color: var(--fm-navy); margin: 0 0 8px; }
.fm-order-response pre {
  margin: 0; font-size: 12.5px; font-family: 'JetBrains Mono', monospace;
  color: var(--fm-text); white-space: pre-wrap; word-break: break-all;
  max-height: 200px; overflow-y: auto;
}

/* ── Modal ── */
.fm-modal { border-radius: var(--fm-radius); overflow: hidden; font-family: var(--fm-font); border: none; }
.fm-modal-header {
  background: var(--fm-teal); color: #fff; padding: 14px 20px;
  border-bottom: none;
}
.fm-modal-header .modal-title { font-size: 15px; font-weight: 700; }
.fm-modal-header .modal-title i { margin-right: 8px; }
.fm-modal-close { color: #fff; opacity: .7; text-shadow: none; font-size: 22px; }
.fm-modal-close:hover { opacity: 1; color: #fff; }
.fm-modal-header-warn { background: var(--fm-red); }
.fm-modal-body { padding: 20px; }
.fm-modal-footer { padding: 12px 20px; border-top: 1px solid var(--fm-border); display: flex; gap: 8px; justify-content: flex-end; }

/* ── Detail Grid ── */
.fm-detail-grid { display: flex; flex-direction: column; gap: 0; }
.fm-detail-row { display: grid; grid-template-columns: 1fr 1fr; border-bottom: 1px solid var(--fm-border); }
.fm-detail-row-full { grid-template-columns: 1fr; }
.fm-detail-cell { padding: 10px 14px; position: relative; }
.fm-detail-row .fm-detail-cell:first-child { border-right: 1px solid var(--fm-border); }
.fm-detail-row-full .fm-detail-cell { border-right: none; }
.fm-detail-label { display: block; font-size: 10.5px; font-weight: 600; color: var(--fm-text-secondary); text-transform: uppercase; letter-spacing: .3px; margin-bottom: 3px; }
.fm-detail-value { display: block; font-size: 13px; font-weight: 500; color: var(--fm-text); word-break: break-all; }
.fm-detail-amount { font-size: 16px; font-weight: 700; color: var(--fm-teal); font-family: var(--fm-font-display); }

/* ── Copy Button ── */
.fm-copy-btn {
  position: absolute; top: 10px; right: 10px; width: 26px; height: 26px;
  border-radius: 5px; border: 1px solid var(--fm-border); background: var(--fm-white);
  color: var(--fm-text-secondary); cursor: pointer; display: flex; align-items: center;
  justify-content: center; font-size: 12px; transition: all .15s; outline: none; padding: 0;
}
.fm-copy-btn:hover { background: var(--fm-teal); color: #fff; border-color: var(--fm-teal); }

/* ── Timeline ── */
.fm-timeline-section { margin-top: 16px; }
.fm-timeline-title { font-size: 12px; font-weight: 700; color: var(--fm-navy); margin: 0 0 12px; text-transform: uppercase; letter-spacing: .4px; }
.fm-timeline { position: relative; padding-left: 28px; }
.fm-timeline::before {
  content: ''; position: absolute; left: 11px; top: 4px; bottom: 4px;
  width: 2px; background: var(--fm-border);
}
.fm-tl-item { position: relative; padding-bottom: 16px; display: flex; align-items: flex-start; gap: 10px; }
.fm-tl-item:last-child { padding-bottom: 0; }
.fm-tl-dot {
  position: absolute; left: -28px; top: 0; width: 22px; height: 22px;
  border-radius: 50%; background: var(--fm-gray-dim); color: var(--fm-gray);
  display: flex; align-items: center; justify-content: center; font-size: 10px;
  border: 2px solid var(--fm-white); z-index: 1;
}
.fm-tl-done .fm-tl-dot { background: var(--fm-teal-dim); color: var(--fm-teal); }
.fm-tl-success .fm-tl-dot { background: var(--fm-green-dim); color: var(--fm-green); }
.fm-tl-failed .fm-tl-dot { background: var(--fm-red-dim); color: var(--fm-red); }
.fm-tl-refund .fm-tl-dot { background: var(--fm-purple-dim); color: var(--fm-purple); }
.fm-tl-pending .fm-tl-dot { background: var(--fm-gold-dim); color: var(--fm-gold); }
.fm-tl-content { display: flex; flex-direction: column; }
.fm-tl-label { font-size: 13px; font-weight: 600; color: var(--fm-text); }
.fm-tl-time { font-size: 12.5px; color: var(--fm-text-secondary); margin-top: 1px; }

/* ── Refund Modal ── */
.fm-refund-msg { font-size: 13px; color: var(--fm-text); margin: 0 0 12px; }
.fm-refund-info { padding: 10px 14px; background: var(--fm-sky); border-radius: var(--fm-radius-sm); font-size: 12.5px; line-height: 1.8; }

/* ── Toast ── */
.fm-toast {
  position: fixed; bottom: 24px; left: 50%; transform: translateX(-50%) translateY(80px);
  padding: 10px 24px; border-radius: 8px; font-size: 13px; font-weight: 600;
  font-family: var(--fm-font); color: #fff; background: var(--fm-navy); z-index: 99999;
  box-shadow: 0 8px 24px rgba(0,0,0,.18); opacity: 0; transition: all .3s;
  pointer-events: none; white-space: nowrap;
}
.fm-toast-show { opacity: 1; transform: translateX(-50%) translateY(0); pointer-events: auto; }
.fm-toast-error { background: var(--fm-red); }
.fm-toast-success { background: var(--fm-green); }

/* ── Responsive ── */
@media (max-width: 1024px) {
  .fm-stats-row { grid-template-columns: repeat(3, 1fr); }
  .fm-month-grid { grid-template-columns: repeat(4, 1fr); }
}
@media (max-width: 767px) {
  .fm-topbar-inner { flex-direction: column; align-items: flex-start; gap: 4px; }
  .fm-stats-row { grid-template-columns: repeat(2, 1fr); }
  .fm-filter-bar { flex-direction: column; align-items: flex-start; }
  .fm-filter-dates { width: 100%; }
  .fm-detail-row { grid-template-columns: 1fr; }
  .fm-detail-row .fm-detail-cell:first-child { border-right: none; border-bottom: 1px solid var(--fm-border); }
  .fm-month-grid { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 479px) {
  .fm-content { padding: 10px 12px; }
  .fm-stats-row { grid-template-columns: 1fr; }
  .fm-stat-card { padding: 10px 12px; }
  .fm-month-grid { grid-template-columns: repeat(2, 1fr); }
  .fm-table { font-size: 12.5px; }
  .fm-table thead th, .fm-table tbody td { padding: 7px 8px; }
}
</style>
