<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<div class="content-wrapper">
  <!-- ── Top Bar ── -->
  <section class="content-header fm-topbar">
    <div class="fm-topbar-inner">
      <h1 class="fm-page-title"><i class="fa fa-undo"></i> Refund Management</h1>
      <ol class="breadcrumb fm-breadcrumb">
        <li><a href="<?= base_url('dashboard') ?>"><i class="fa fa-dashboard"></i> Dashboard</a></li>
        <li><a href="<?= base_url('fees') ?>">Fees</a></li>
        <li class="active">Refunds</li>
      </ol>
    </div>
  </section>

  <section class="content fm-content">

    <!-- ── Stats Row ── -->
    <div class="fm-stats-row">
      <div class="fm-stat-card fm-stat-total">
        <div class="fm-stat-icon"><i class="fa fa-list-alt"></i></div>
        <div class="fm-stat-body">
          <span class="fm-stat-value" id="statTotal">0</span>
          <span class="fm-stat-label">Total Refunds</span>
        </div>
      </div>
      <div class="fm-stat-card fm-stat-pending">
        <div class="fm-stat-icon"><i class="fa fa-clock-o"></i></div>
        <div class="fm-stat-body">
          <span class="fm-stat-value" id="statPending">0</span>
          <span class="fm-stat-label">Pending</span>
        </div>
      </div>
      <div class="fm-stat-card fm-stat-approved">
        <div class="fm-stat-icon"><i class="fa fa-check-circle"></i></div>
        <div class="fm-stat-body">
          <span class="fm-stat-value" id="statApproved">0</span>
          <span class="fm-stat-label">Approved</span>
        </div>
      </div>
      <div class="fm-stat-card fm-stat-processed">
        <div class="fm-stat-icon"><i class="fa fa-check-double"></i></div>
        <div class="fm-stat-body">
          <span class="fm-stat-value" id="statProcessed">0</span>
          <span class="fm-stat-label">Processed</span>
        </div>
      </div>
      <div class="fm-stat-card fm-stat-rejected">
        <div class="fm-stat-icon"><i class="fa fa-times-circle"></i></div>
        <div class="fm-stat-body">
          <span class="fm-stat-value" id="statRejected">0</span>
          <span class="fm-stat-label">Rejected</span>
        </div>
      </div>
    </div>

    <!-- ── Filter Bar ── -->
    <div class="fm-filter-bar">
      <div class="fm-filter-pills">
        <button class="fm-pill active" data-status="all">All</button>
        <button class="fm-pill" data-status="pending">Pending</button>
        <button class="fm-pill" data-status="approved">Approved</button>
        <button class="fm-pill" data-status="processed">Processed</button>
        <button class="fm-pill" data-status="rejected">Rejected</button>
      </div>
      <button class="fm-btn fm-btn-outline" id="toggleCreateForm">
        <i class="fa fa-plus"></i> New Request
      </button>
    </div>

    <!-- ── Create Refund Request ── -->
    <div class="fm-card fm-create-card" id="createRefundCard" style="display:none;">
      <div class="fm-card-header">
        <h3 class="fm-card-title"><i class="fa fa-plus-circle"></i> Create Refund Request</h3>
        <button class="fm-card-collapse" id="collapseCreate"><i class="fa fa-chevron-up"></i></button>
      </div>
      <div class="fm-card-body" id="createFormBody">
        <form id="createRefundForm" autocomplete="off">
          <div class="row">
            <div class="col-md-3 col-sm-6">
              <div class="fm-form-group">
                <label class="fm-label">Student ID <span class="fm-req">*</span></label>
                <input type="text" class="fm-input" id="rfStudentId" name="student_id" placeholder="Enter Student ID" required>
                <span class="fm-input-hint" id="studentLookupStatus"></span>
              </div>
            </div>
            <div class="col-md-3 col-sm-6">
              <div class="fm-form-group">
                <label class="fm-label">Student Name</label>
                <input type="text" class="fm-input fm-readonly" id="rfStudentName" readonly tabindex="-1">
              </div>
            </div>
            <div class="col-md-3 col-sm-6">
              <div class="fm-form-group">
                <label class="fm-label">Class / Section</label>
                <input type="text" class="fm-input fm-readonly" id="rfClassSection" readonly tabindex="-1">
              </div>
            </div>
            <div class="col-md-3 col-sm-6">
              <div class="fm-form-group">
                <label class="fm-label">Fee Title <span class="fm-req">*</span></label>
                <select class="fm-select" id="rfFeeTitle" name="fee_title" required>
                  <option value="">-- Select Fee --</option>
                  <?php if (!empty($fee_titles)): ?>
                    <?php foreach ($fee_titles as $title): ?>
                      <option value="<?= htmlspecialchars($title) ?>"><?= htmlspecialchars($title) ?></option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>
            </div>
          </div>
          <div class="row">
            <div class="col-md-3 col-sm-6">
              <div class="fm-form-group">
                <label class="fm-label">Receipt No <span class="fm-req">*</span></label>
                <input type="text" class="fm-input" id="rfReceiptNo" name="receipt_no" placeholder="e.g. F000012" required>
              </div>
            </div>
            <div class="col-md-3 col-sm-6">
              <div class="fm-form-group">
                <label class="fm-label">Amount (&#8377;) <span class="fm-req">*</span></label>
                <input type="number" class="fm-input" id="rfAmount" name="amount" placeholder="0.00" min="1" step="0.01" required>
              </div>
            </div>
            <div class="col-md-6">
              <div class="fm-form-group">
                <label class="fm-label">Reason <span class="fm-req">*</span></label>
                <textarea class="fm-textarea" id="rfReason" name="reason" rows="2" placeholder="Reason for refund request" required></textarea>
              </div>
            </div>
          </div>
          <div class="fm-form-actions">
            <button type="submit" class="fm-btn fm-btn-primary" id="btnSubmitRefund">
              <i class="fa fa-paper-plane"></i> Submit Request
            </button>
            <button type="reset" class="fm-btn fm-btn-ghost" id="btnResetForm">
              <i class="fa fa-eraser"></i> Clear
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- ── Refunds Table ── -->
    <div class="fm-card">
      <div class="fm-card-header">
        <h3 class="fm-card-title"><i class="fa fa-table"></i> Refund Records</h3>
        <button class="fm-btn fm-btn-sm fm-btn-outline" id="btnRefresh" title="Refresh">
          <i class="fa fa-refresh"></i>
        </button>
      </div>
      <div class="fm-card-body fm-card-body-table">
        <div class="table-responsive">
          <table class="fm-table" id="refundsTable">
            <thead>
              <tr>
                <th width="50">S.No</th>
                <th>Date</th>
                <th>Student Name</th>
                <th>Class</th>
                <th>Fee Title</th>
                <th>Receipt No</th>
                <th class="text-right">Amount (&#8377;)</th>
                <th class="text-center">Status</th>
                <th class="text-center" width="160">Actions</th>
              </tr>
            </thead>
            <tbody id="refundsTableBody">
              <tr class="fm-empty-row">
                <td colspan="9">
                  <div class="fm-empty-state">
                    <i class="fa fa-inbox"></i>
                    <p>Loading refund records...</p>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </section>
</div>

<!-- ── Process Refund Modal ── -->
<div class="modal fade" id="processModal" tabindex="-1" role="dialog">
  <div class="modal-dialog fm-modal-dialog" role="document">
    <div class="modal-content fm-modal-content">
      <div class="fm-modal-header">
        <h4 class="fm-modal-title"><i class="fa fa-cog"></i> Process Refund</h4>
        <button type="button" class="fm-modal-close" data-dismiss="modal"><i class="fa fa-times"></i></button>
      </div>
      <div class="fm-modal-body">
        <input type="hidden" id="processRefundId">
        <div class="fm-detail-grid">
          <div class="fm-detail-item">
            <span class="fm-detail-label">Student</span>
            <span class="fm-detail-value" id="pmStudentName">--</span>
          </div>
          <div class="fm-detail-item">
            <span class="fm-detail-label">Class</span>
            <span class="fm-detail-value" id="pmClass">--</span>
          </div>
          <div class="fm-detail-item">
            <span class="fm-detail-label">Fee Title</span>
            <span class="fm-detail-value" id="pmFeeTitle">--</span>
          </div>
          <div class="fm-detail-item">
            <span class="fm-detail-label">Receipt No</span>
            <span class="fm-detail-value" id="pmReceiptNo">--</span>
          </div>
          <div class="fm-detail-item">
            <span class="fm-detail-label">Amount</span>
            <span class="fm-detail-value fm-text-money" id="pmAmount">--</span>
          </div>
          <div class="fm-detail-item">
            <span class="fm-detail-label">Reason</span>
            <span class="fm-detail-value" id="pmReason">--</span>
          </div>
        </div>
        <hr class="fm-divider">
        <div class="fm-form-group">
          <label class="fm-label">Refund Mode <span class="fm-req">*</span></label>
          <select class="fm-select" id="pmRefundMode" required>
            <option value="">-- Select Mode --</option>
            <option value="Cash">Cash</option>
            <option value="Bank Transfer">Bank Transfer</option>
            <option value="Cheque">Cheque</option>
            <option value="Adjustment">Adjustment</option>
          </select>
        </div>
        <div class="fm-form-group">
          <label class="fm-label">Remarks</label>
          <textarea class="fm-textarea" id="pmRemarks" rows="2" placeholder="Optional remarks"></textarea>
        </div>
      </div>
      <div class="fm-modal-footer">
        <button type="button" class="fm-btn fm-btn-ghost" data-dismiss="modal">Cancel</button>
        <button type="button" class="fm-btn fm-btn-primary" id="btnProcessRefund">
          <i class="fa fa-check"></i> Process Refund
        </button>
      </div>
    </div>
  </div>
</div>

<!-- ── View Details Modal ── -->
<div class="modal fade" id="detailsModal" tabindex="-1" role="dialog">
  <div class="modal-dialog fm-modal-dialog" role="document">
    <div class="modal-content fm-modal-content">
      <div class="fm-modal-header">
        <h4 class="fm-modal-title"><i class="fa fa-eye"></i> Refund Details</h4>
        <button type="button" class="fm-modal-close" data-dismiss="modal"><i class="fa fa-times"></i></button>
      </div>
      <div class="fm-modal-body">
        <div class="fm-detail-grid">
          <div class="fm-detail-item">
            <span class="fm-detail-label">Student</span>
            <span class="fm-detail-value" id="dmStudentName">--</span>
          </div>
          <div class="fm-detail-item">
            <span class="fm-detail-label">Class</span>
            <span class="fm-detail-value" id="dmClass">--</span>
          </div>
          <div class="fm-detail-item">
            <span class="fm-detail-label">Fee Title</span>
            <span class="fm-detail-value" id="dmFeeTitle">--</span>
          </div>
          <div class="fm-detail-item">
            <span class="fm-detail-label">Receipt No</span>
            <span class="fm-detail-value" id="dmReceiptNo">--</span>
          </div>
          <div class="fm-detail-item">
            <span class="fm-detail-label">Amount</span>
            <span class="fm-detail-value fm-text-money" id="dmAmount">--</span>
          </div>
          <div class="fm-detail-item">
            <span class="fm-detail-label">Reason</span>
            <span class="fm-detail-value" id="dmReason">--</span>
          </div>
          <div class="fm-detail-item">
            <span class="fm-detail-label">Status</span>
            <span class="fm-detail-value" id="dmStatus">--</span>
          </div>
          <div class="fm-detail-item">
            <span class="fm-detail-label">Created</span>
            <span class="fm-detail-value" id="dmCreated">--</span>
          </div>
          <div class="fm-detail-item" id="dmRefundModeRow" style="display:none;">
            <span class="fm-detail-label">Refund Mode</span>
            <span class="fm-detail-value" id="dmRefundMode">--</span>
          </div>
          <div class="fm-detail-item" id="dmRemarksRow" style="display:none;">
            <span class="fm-detail-label">Remarks</span>
            <span class="fm-detail-value" id="dmRemarks">--</span>
          </div>
          <div class="fm-detail-item" id="dmProcessedDateRow" style="display:none;">
            <span class="fm-detail-label">Processed On</span>
            <span class="fm-detail-value" id="dmProcessedDate">--</span>
          </div>
        </div>
      </div>
      <div class="fm-modal-footer">
        <button type="button" class="fm-btn fm-btn-ghost" data-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ── Toast Container ── -->
<div id="fmToastContainer"></div>


<script>
document.addEventListener('DOMContentLoaded', function() {

  /* ── CSRF ── */
  var csrfName  = document.querySelector('meta[name="csrf-name"]').content;
  var csrfHash  = document.querySelector('meta[name="csrf-token"]').content;
  var BASE      = '<?= base_url() ?>';
  var activeFilter = 'all';
  var refundsCache = [];

  /* ── Helpers ── */
  function csrfData(obj) {
    obj = obj || {};
    obj[csrfName] = csrfHash;
    return obj;
  }

  function updateCsrf(xhr) {
    var h = xhr.getResponseHeader('X-CSRF-TOKEN');
    if (h) csrfHash = h;
  }

  function ajaxPost(url, data, cb) {
    $.ajax({
      url: BASE + url,
      type: 'POST',
      data: csrfData(data),
      dataType: 'json',
      success: function(res, status, xhr) {
        updateCsrf(xhr);
        cb(null, res);
      },
      error: function(xhr) {
        updateCsrf(xhr);
        var msg = 'Request failed';
        try { msg = JSON.parse(xhr.responseText).message || msg; } catch(e) {}
        cb(msg, null);
      }
    });
  }

  function fmtDate(d) {
    if (!d) return '--';
    var dt = new Date(d);
    if (isNaN(dt)) return d;
    return dt.toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' });
  }

  function fmtAmount(n) {
    n = parseFloat(n) || 0;
    return n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function statusBadge(s) {
    var cls = {
      pending:   'fm-badge-pending',
      approved:  'fm-badge-approved',
      processed: 'fm-badge-processed',
      rejected:  'fm-badge-rejected'
    };
    var label = s.charAt(0).toUpperCase() + s.slice(1);
    return '<span class="fm-badge ' + (cls[s] || '') + '">' + label + '</span>';
  }

  function actionButtons(item) {
    var s = (item.status || '').toLowerCase();
    var id = item.id;
    var btns = '';
    if (s === 'pending') {
      btns += '<button class="fm-btn-xs fm-btn-approve" onclick="approveRefund(\'' + id + '\')" title="Approve"><i class="fa fa-check"></i></button>';
      btns += '<button class="fm-btn-xs fm-btn-reject" onclick="rejectRefund(\'' + id + '\')" title="Reject"><i class="fa fa-times"></i></button>';
    } else if (s === 'approved') {
      btns += '<button class="fm-btn-xs fm-btn-process" onclick="showProcessModal(\'' + id + '\')" title="Process"><i class="fa fa-cog"></i> Process</button>';
    }
    btns += '<button class="fm-btn-xs fm-btn-view" onclick="showDetails(\'' + id + '\')" title="View"><i class="fa fa-eye"></i></button>';
    return btns;
  }

  /* ── Load Refunds ── */
  function loadRefunds(status) {
    status = status || 'all';
    var $tbody = $('#refundsTableBody');
    $tbody.html('<tr class="fm-empty-row"><td colspan="9"><div class="fm-empty-state"><i class="fa fa-spinner fa-spin"></i><p>Loading...</p></div></td></tr>');

    ajaxPost('fee_management/fetch_refunds', { status: status }, function(err, res) {
      if (err || !res || !res.success) {
        $tbody.html('<tr class="fm-empty-row"><td colspan="9"><div class="fm-empty-state"><i class="fa fa-exclamation-triangle"></i><p>' + (err || 'Failed to load refunds') + '</p></div></td></tr>');
        return;
      }
      var items = res.refunds || [];
      refundsCache = items;

      /* Update stats */
      var stats = res.stats || {};
      $('#statTotal').text(stats.total || 0);
      $('#statPending').text(stats.pending || 0);
      $('#statApproved').text(stats.approved || 0);
      $('#statProcessed').text(stats.processed || 0);
      $('#statRejected').text(stats.rejected || 0);

      if (!items.length) {
        $tbody.html('<tr class="fm-empty-row"><td colspan="9"><div class="fm-empty-state"><i class="fa fa-inbox"></i><p>No refund records found</p></div></td></tr>');
        return;
      }

      var html = '';
      for (var i = 0; i < items.length; i++) {
        var r = items[i];
        html += '<tr>'
          + '<td>' + (i + 1) + '</td>'
          + '<td>' + fmtDate(r.date) + '</td>'
          + '<td class="fm-text-name">' + (r.student_name || '--') + '</td>'
          + '<td>' + (r.class_section || '--') + '</td>'
          + '<td>' + (r.fee_title || '--') + '</td>'
          + '<td><code class="fm-code">' + (r.receipt_no || '--') + '</code></td>'
          + '<td class="text-right fm-text-money">' + fmtAmount(r.amount) + '</td>'
          + '<td class="text-center">' + statusBadge(r.status || 'pending') + '</td>'
          + '<td class="text-center fm-actions">' + actionButtons(r) + '</td>'
          + '</tr>';
      }
      $tbody.html(html);
    });
  }

  /* ── Filter by Status ── */
  function filterByStatus(status) {
    activeFilter = status;
    $('.fm-pill').removeClass('active');
    $('.fm-pill[data-status="' + status + '"]').addClass('active');
    loadRefunds(status);
  }

  /* ── Create Refund ── */
  function createRefund(e) {
    e.preventDefault();
    var sid = $('#rfStudentId').val().trim();
    var name = $('#rfStudentName').val().trim();
    if (!sid || !name) {
      showToast('Please enter a valid Student ID and wait for lookup', 'error');
      return;
    }
    var data = {
      student_id:    sid,
      student_name:  name,
      class_section: $('#rfClassSection').val(),
      fee_title:     $('#rfFeeTitle').val(),
      receipt_no:    $('#rfReceiptNo').val().trim(),
      amount:        $('#rfAmount').val(),
      reason:        $('#rfReason').val().trim()
    };
    if (!data.fee_title || !data.receipt_no || !data.amount || !data.reason) {
      showToast('Please fill all required fields', 'error');
      return;
    }

    var $btn = $('#btnSubmitRefund');
    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Submitting...');

    ajaxPost('fee_management/create_refund', data, function(err, res) {
      $btn.prop('disabled', false).html('<i class="fa fa-paper-plane"></i> Submit Request');
      if (err || !res || !res.success) {
        showToast(err || res.message || 'Failed to create refund', 'error');
        return;
      }
      showToast('Refund request created successfully', 'success');
      $('#createRefundForm')[0].reset();
      $('#rfStudentName, #rfClassSection').val('');
      $('#studentLookupStatus').text('');
      loadRefunds(activeFilter);
    });
  }

  /* ── Lookup Student ── */
  function lookupStudent() {
    var uid = $('#rfStudentId').val().trim();
    var $hint = $('#studentLookupStatus');
    if (!uid) {
      $hint.text('').removeClass('fm-hint-ok fm-hint-err');
      $('#rfStudentName, #rfClassSection').val('');
      return;
    }
    $hint.text('Looking up...').removeClass('fm-hint-ok fm-hint-err');

    ajaxPost('fees/lookup_student', { user_id: uid }, function(err, res) {
      if (err || !res || !res.name) {
        $hint.text('Student not found').addClass('fm-hint-err').removeClass('fm-hint-ok');
        $('#rfStudentName, #rfClassSection').val('');
        return;
      }
      $hint.text('Found').addClass('fm-hint-ok').removeClass('fm-hint-err');
      $('#rfStudentName').val(res.name);
      $('#rfClassSection').val(res.class || '');
    });
  }

  /* ── Approve / Reject ── */
  window.approveRefund = function(id) {
    if (!confirm('Approve this refund request?')) return;
    ajaxPost('fee_management/approve_refund', { refund_id: id }, function(err, res) {
      if (err || !res || !res.success) {
        showToast(err || 'Approval failed', 'error');
        return;
      }
      showToast('Refund approved', 'success');
      loadRefunds(activeFilter);
    });
  };

  window.rejectRefund = function(id) {
    if (!confirm('Reject this refund request?')) return;
    ajaxPost('fee_management/reject_refund', { refund_id: id }, function(err, res) {
      if (err || !res || !res.success) {
        showToast(err || 'Rejection failed', 'error');
        return;
      }
      showToast('Refund rejected', 'success');
      loadRefunds(activeFilter);
    });
  };

  /* ── Process Modal ── */
  window.showProcessModal = function(id) {
    var item = refundsCache.find(function(r) { return r.id === id; });
    if (!item) return;
    $('#processRefundId').val(id);
    $('#pmStudentName').text(item.student_name || '--');
    $('#pmClass').text(item.class_section || '--');
    $('#pmFeeTitle').text(item.fee_title || '--');
    $('#pmReceiptNo').text(item.receipt_no || '--');
    $('#pmAmount').text(fmtAmount(item.amount));
    $('#pmReason').text(item.reason || '--');
    $('#pmRefundMode').val('');
    $('#pmRemarks').val('');
    $('#processModal').modal('show');
  };

  function processRefund() {
    var id   = $('#processRefundId').val();
    var mode = $('#pmRefundMode').val();
    if (!mode) {
      showToast('Please select a refund mode', 'error');
      return;
    }
    var $btn = $('#btnProcessRefund');
    $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i> Processing...');

    ajaxPost('fee_management/process_refund', {
      refund_id:   id,
      refund_mode: mode,
      remarks:     $('#pmRemarks').val().trim()
    }, function(err, res) {
      $btn.prop('disabled', false).html('<i class="fa fa-check"></i> Process Refund');
      if (err || !res || !res.success) {
        showToast(err || 'Processing failed', 'error');
        return;
      }
      showToast('Refund processed successfully', 'success');
      $('#processModal').modal('hide');
      loadRefunds(activeFilter);
    });
  }

  /* ── View Details Modal ── */
  window.showDetails = function(id) {
    var item = refundsCache.find(function(r) { return r.id === id; });
    if (!item) return;
    $('#dmStudentName').text(item.student_name || '--');
    $('#dmClass').text(item.class_section || '--');
    $('#dmFeeTitle').text(item.fee_title || '--');
    $('#dmReceiptNo').text(item.receipt_no || '--');
    $('#dmAmount').text(fmtAmount(item.amount));
    $('#dmReason').text(item.reason || '--');
    $('#dmStatus').html(statusBadge(item.status || 'pending'));
    $('#dmCreated').text(fmtDate(item.date));

    if (item.status === 'processed') {
      $('#dmRefundModeRow').show();
      $('#dmRefundMode').text(item.refund_mode || '--');
      $('#dmRemarksRow').show();
      $('#dmRemarks').text(item.remarks || '--');
      $('#dmProcessedDateRow').show();
      $('#dmProcessedDate').text(fmtDate(item.processed_date));
    } else {
      $('#dmRefundModeRow, #dmRemarksRow, #dmProcessedDateRow').hide();
    }
    $('#detailsModal').modal('show');
  };

  /* ── Toast ── */
  function showToast(msg, type) {
    type = type || 'info';
    var icons = { success:'fa-check-circle', error:'fa-exclamation-circle', info:'fa-info-circle' };
    var $t = $('<div class="fm-toast fm-toast-' + type + '"><i class="fa ' + (icons[type]||icons.info) + '"></i><span>' + msg + '</span></div>');
    $('#fmToastContainer').append($t);
    setTimeout(function() { $t.addClass('fm-toast-show'); }, 10);
    setTimeout(function() {
      $t.removeClass('fm-toast-show');
      setTimeout(function() { $t.remove(); }, 300);
    }, 3500);
  }

  /* ── Event Bindings ── */
  $('.fm-pill').on('click', function() {
    filterByStatus($(this).data('status'));
  });

  $('#toggleCreateForm').on('click', function() {
    $('#createRefundCard').slideToggle(200);
  });

  $('#collapseCreate').on('click', function() {
    $('#createFormBody').slideToggle(200);
    $(this).find('i').toggleClass('fa-chevron-up fa-chevron-down');
  });

  $('#rfStudentId').on('blur', lookupStudent);

  $('#createRefundForm').on('submit', createRefund);

  $('#btnResetForm').on('click', function() {
    $('#rfStudentName, #rfClassSection').val('');
    $('#studentLookupStatus').text('').removeClass('fm-hint-ok fm-hint-err');
  });

  $('#btnProcessRefund').on('click', processRefund);

  $('#btnRefresh').on('click', function() {
    loadRefunds(activeFilter);
  });

  /* ── Init ── */
  loadRefunds('all');

});
</script>

<style>
/* ================================================================
   Fee Management — Refunds  |  fm-* prefix
   Fonts: Plus Jakarta Sans (body), Fraunces (headings)
   Palette: Navy #0c1e38 / Teal #0f766e
   ================================================================ */
@import url('https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,400;9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap');

/* ── Layout ── */
.fm-content { padding: 0 18px 24px; }
.fm-topbar { padding: 14px 18px 0; margin-bottom: 0; }
.fm-topbar-inner { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.fm-page-title {
  font-family: 'Fraunces', var(--font-b);
  font-size: 1.3rem;
  font-weight: 700;
  color: var(--t1);
  margin: 0;
  display: flex;
  align-items: center;
  gap: 10px;
}
.fm-page-title i { color: var(--gold); font-size: 1.1rem; }
.fm-breadcrumb {
  background: none;
  padding: 0;
  margin: 0;
  font-size: .78rem;
  color: var(--t3);
}
.fm-breadcrumb a { color: var(--gold); }
.fm-breadcrumb .active { color: var(--t2); }

/* ── Stats Row ── */
.fm-stats-row {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 12px;
  margin-bottom: 14px;
}
.fm-stat-card {
  background: var(--card, var(--bg2));
  border: 1px solid var(--border);
  border-radius: var(--r-sm);
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  box-shadow: var(--sh);
  transition: transform .18s ease, box-shadow .18s ease;
}
.fm-stat-card:hover { transform: translateY(-1px); }
.fm-stat-icon {
  width: 38px; height: 38px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1rem;
  flex-shrink: 0;
}
.fm-stat-total .fm-stat-icon   { background: rgba(15,118,110,.12); color: #0f766e; }
.fm-stat-pending .fm-stat-icon  { background: rgba(217,119,6,.12); color: #d97706; }
.fm-stat-approved .fm-stat-icon { background: rgba(59,130,246,.12); color: #3b82f6; }
.fm-stat-processed .fm-stat-icon{ background: rgba(21,128,61,.12); color: #15803d; }
.fm-stat-rejected .fm-stat-icon { background: rgba(224,92,111,.12); color: #E05C6F; }
.fm-stat-body { display: flex; flex-direction: column; }
.fm-stat-value {
  font-family: 'Fraunces', var(--font-b);
  font-size: 1.25rem;
  font-weight: 700;
  color: var(--t1);
  line-height: 1.1;
}
.fm-stat-label {
  font-size: .7rem;
  font-weight: 500;
  color: var(--t3);
  text-transform: uppercase;
  letter-spacing: .4px;
  margin-top: 2px;
}

/* ── Filter Bar ── */
.fm-filter-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 14px;
}
.fm-filter-pills { display: flex; gap: 6px; flex-wrap: wrap; }
.fm-pill {
  padding: 5px 16px;
  border-radius: 20px;
  border: 1px solid var(--border);
  background: transparent;
  color: var(--t2);
  font-family: 'Plus Jakarta Sans', var(--font-b);
  font-size: .78rem;
  font-weight: 600;
  cursor: pointer;
  transition: all .18s ease;
}
.fm-pill:hover { border-color: var(--gold); color: var(--gold); }
.fm-pill.active {
  background: var(--gold);
  border-color: var(--gold);
  color: #fff;
}

/* ── Cards ── */
.fm-card {
  background: var(--card, var(--bg2));
  border: 1px solid var(--border);
  border-radius: var(--r);
  box-shadow: var(--sh);
  margin-bottom: 14px;
  overflow: hidden;
}
.fm-card-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 18px;
  border-bottom: 1px solid var(--border);
}
.fm-card-title {
  font-family: 'Fraunces', var(--font-b);
  font-size: .95rem;
  font-weight: 600;
  color: var(--t1);
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}
.fm-card-title i { color: var(--gold); font-size: .85rem; }
.fm-card-collapse {
  background: none;
  border: none;
  color: var(--t3);
  cursor: pointer;
  padding: 4px;
  font-size: .85rem;
}
.fm-card-collapse:hover { color: var(--gold); }
.fm-card-body { padding: 16px 18px; }
.fm-card-body-table { padding: 0; }

/* ── Form Elements ── */
.fm-form-group { margin-bottom: 12px; }
.fm-label {
  display: block;
  font-size: .73rem;
  font-weight: 600;
  color: var(--t2);
  text-transform: uppercase;
  letter-spacing: .3px;
  margin-bottom: 4px;
}
.fm-req { color: #E05C6F; }
.fm-input, .fm-select, .fm-textarea {
  width: 100%;
  padding: 7px 11px;
  font-family: 'Plus Jakarta Sans', var(--font-b);
  font-size: .82rem;
  color: var(--t1);
  background: var(--bg);
  border: 1px solid var(--border);
  border-radius: 6px;
  transition: border-color .18s ease, box-shadow .18s ease;
  outline: none;
}
.fm-input:focus, .fm-select:focus, .fm-textarea:focus {
  border-color: var(--gold);
  box-shadow: 0 0 0 3px var(--gold-ring);
}
.fm-input.fm-readonly {
  background: var(--bg3);
  color: var(--t3);
  cursor: default;
}
.fm-textarea { resize: vertical; min-height: 44px; }
.fm-select { cursor: pointer; }
.fm-input-hint {
  display: block;
  font-size: .7rem;
  margin-top: 2px;
  min-height: 14px;
  color: var(--t3);
}
.fm-hint-ok { color: #15803d !important; }
.fm-hint-err { color: #E05C6F !important; }
.fm-form-actions {
  display: flex;
  gap: 8px;
  padding-top: 4px;
}

/* ── Buttons ── */
.fm-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 7px 18px;
  font-family: 'Plus Jakarta Sans', var(--font-b);
  font-size: .8rem;
  font-weight: 600;
  border: none;
  border-radius: 6px;
  cursor: pointer;
  transition: all .18s ease;
  white-space: nowrap;
}
.fm-btn-primary {
  background: var(--gold);
  color: #fff;
}
.fm-btn-primary:hover { background: var(--gold2); box-shadow: 0 2px 8px var(--gold-glow); }
.fm-btn-outline {
  background: transparent;
  border: 1px solid var(--gold);
  color: var(--gold);
}
.fm-btn-outline:hover { background: var(--gold-dim); }
.fm-btn-ghost {
  background: transparent;
  color: var(--t3);
  border: 1px solid var(--border);
}
.fm-btn-ghost:hover { color: var(--t1); border-color: var(--t3); }
.fm-btn-sm { padding: 4px 10px; font-size: .75rem; }

/* ── Table Action Buttons ── */
.fm-btn-xs {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  font-size: .7rem;
  font-weight: 600;
  border: none;
  border-radius: 4px;
  cursor: pointer;
  transition: all .15s ease;
  font-family: 'Plus Jakarta Sans', var(--font-b);
  margin: 1px;
}
.fm-btn-approve { background: rgba(21,128,61,.12); color: #15803d; }
.fm-btn-approve:hover { background: rgba(21,128,61,.22); }
.fm-btn-reject { background: rgba(224,92,111,.12); color: #E05C6F; }
.fm-btn-reject:hover { background: rgba(224,92,111,.22); }
.fm-btn-process { background: rgba(59,130,246,.12); color: #3b82f6; }
.fm-btn-process:hover { background: rgba(59,130,246,.22); }
.fm-btn-view { background: var(--gold-dim); color: var(--gold); }
.fm-btn-view:hover { background: var(--gold-ring); }

/* ── Table ── */
.fm-table {
  width: 100%;
  border-collapse: collapse;
  font-size: .8rem;
}
.fm-table thead th {
  padding: 8px 10px;
  font-size: .7rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: .4px;
  color: var(--t3);
  background: var(--bg3);
  border-bottom: 1px solid var(--border);
  white-space: nowrap;
}
.fm-table tbody td {
  padding: 8px 10px;
  border-bottom: 1px solid var(--border);
  color: var(--t1);
  vertical-align: middle;
}
.fm-table tbody tr:hover { background: var(--gold-dim); }
.fm-table tbody tr:last-child td { border-bottom: none; }
.fm-text-name { font-weight: 600; }
.fm-text-money {
  font-family: 'JetBrains Mono', var(--font-m);
  font-weight: 500;
  font-size: .78rem;
}
.fm-code {
  font-family: 'JetBrains Mono', var(--font-m);
  font-size: .72rem;
  background: var(--bg3);
  padding: 2px 6px;
  border-radius: 3px;
  color: var(--t2);
}
.fm-actions { white-space: nowrap; }

/* ── Status Badges ── */
.fm-badge {
  display: inline-block;
  padding: 2px 10px;
  font-size: .68rem;
  font-weight: 700;
  border-radius: 20px;
  text-transform: uppercase;
  letter-spacing: .3px;
}
.fm-badge-pending   { background: rgba(217,119,6,.12); color: #d97706; }
.fm-badge-approved  { background: rgba(59,130,246,.12); color: #3b82f6; }
.fm-badge-processed { background: rgba(21,128,61,.12);  color: #15803d; }
.fm-badge-rejected  { background: rgba(224,92,111,.12); color: #E05C6F; }

/* ── Empty State ── */
.fm-empty-state {
  text-align: center;
  padding: 36px 16px;
  color: var(--t3);
}
.fm-empty-state i { font-size: 2rem; margin-bottom: 8px; display: block; opacity: .5; }
.fm-empty-state p { margin: 0; font-size: .82rem; }

/* ── Modal ── */
.fm-modal-dialog { max-width: 520px; }
.fm-modal-content {
  background: var(--card, var(--bg2));
  border: 1px solid var(--border);
  border-radius: var(--r);
  box-shadow: 0 12px 48px rgba(0,0,0,.35);
}
.fm-modal-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 20px;
  border-bottom: 1px solid var(--border);
}
.fm-modal-title {
  font-family: 'Fraunces', var(--font-b);
  font-size: .95rem;
  font-weight: 600;
  color: var(--t1);
  margin: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}
.fm-modal-title i { color: var(--gold); }
.fm-modal-close {
  background: none;
  border: none;
  color: var(--t3);
  font-size: 1rem;
  cursor: pointer;
  padding: 4px;
  line-height: 1;
}
.fm-modal-close:hover { color: var(--t1); }
.fm-modal-body { padding: 16px 20px; }
.fm-modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
  padding: 12px 20px;
  border-top: 1px solid var(--border);
}

/* ── Detail Grid ── */
.fm-detail-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px 16px;
}
.fm-detail-item { display: flex; flex-direction: column; gap: 2px; }
.fm-detail-label {
  font-size: .68rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: .3px;
  color: var(--t3);
}
.fm-detail-value {
  font-size: .82rem;
  color: var(--t1);
  word-break: break-word;
}
.fm-divider {
  border: none;
  border-top: 1px solid var(--border);
  margin: 14px 0;
}

/* ── Toast ── */
#fmToastContainer {
  position: fixed;
  top: 16px;
  right: 16px;
  z-index: 99999;
  display: flex;
  flex-direction: column;
  gap: 8px;
  pointer-events: none;
}
.fm-toast {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 10px 18px;
  border-radius: 8px;
  font-family: 'Plus Jakarta Sans', var(--font-b);
  font-size: .82rem;
  font-weight: 500;
  box-shadow: 0 6px 24px rgba(0,0,0,.2);
  pointer-events: auto;
  transform: translateX(110%);
  transition: transform .3s cubic-bezier(.4,0,.2,1);
  max-width: 380px;
}
.fm-toast-show { transform: translateX(0); }
.fm-toast-success { background: #15803d; color: #fff; }
.fm-toast-error   { background: #dc2626; color: #fff; }
.fm-toast-info    { background: #3b82f6; color: #fff; }

/* ── Responsive ── */
@media (max-width: 1200px) {
  .fm-stats-row { grid-template-columns: repeat(3, 1fr); }
}
@media (max-width: 767px) {
  .fm-stats-row { grid-template-columns: repeat(2, 1fr); }
  .fm-topbar-inner { flex-direction: column; align-items: flex-start; }
  .fm-detail-grid { grid-template-columns: 1fr; }
  .fm-filter-bar { flex-direction: column; align-items: flex-start; }
  .fm-content { padding: 0 10px 18px; }
}
@media (max-width: 479px) {
  .fm-stats-row { grid-template-columns: 1fr; }
  .fm-form-actions { flex-direction: column; }
  .fm-form-actions .fm-btn { width: 100%; justify-content: center; }
}
</style>
