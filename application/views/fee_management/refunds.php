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
        <button class="fm-pill" data-status="processing">Processing</button>
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
                <!-- Hidden split-out fields so the controller gets an
                     unambiguous class + section pair, not a combined string
                     that has to be parsed. Populated by lookupStudent(). -->
                <input type="hidden" id="rfClassHidden">
                <input type="hidden" id="rfSectionHidden">
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
                <input type="text" class="fm-input" id="rfReceiptNo" name="receipt_no"
                       placeholder="e.g. 1  (or R000001 / F1 — any form works)" required>
                <small class="fm-form-hint" style="margin-top:4px;display:block;color:var(--t3);font-size:0.78rem;">
                    Enter whatever receipt number you saw after collecting the fee — numeric ("1"), formatted ("R000001"), or F-prefixed ("F1"). The system normalises all forms.
                </small>
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
                <th>Class / Section</th>
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
<!-- Process modal: static backdrop + ESC disabled so a stray click or
     keystroke mid-process can't dismiss the dialog while an AJAX write is
     in flight. User must use Cancel or OK to close. -->
<div class="modal fade" id="processModal" tabindex="-1" role="dialog"
     data-backdrop="static" data-keyboard="false">
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
            <span class="fm-detail-label">Class / Section</span>
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
            <option value="cash">Cash</option>
            <option value="bank_transfer">Bank Transfer</option>
            <option value="cheque">Cheque</option>
            <option value="online">Online / Adjustment</option>
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
            <span class="fm-detail-label">Class / Section</span>
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

  /* ───────────────────────────────────────────────────────────────
   * Stage B2 (2026-05-10) — Custom confirmation dialog.
   * Replaces native confirm() for irreversible refund actions.
   *
   * Why:
   *   - confirm() can be auto-clicked by accessibility tools, browser
   *     extensions, or accidental Enter-key presses on focused
   *     elements. Refund approvals/rejections are irreversible from
   *     the UI; we need an explicit, focus-managed dialog.
   *   - confirm() text doesn't render line breaks consistently
   *     across browsers; long messages got squashed.
   *
   * Behaviour:
   *   - Backdrop blocks page interaction.
   *   - Esc key resolves false (cancel).
   *   - Backdrop click does NOT dismiss (must explicitly click a button).
   *   - Tab/Shift-Tab cycle between Cancel and Confirm.
   *   - Default focus lands on Cancel — the safer choice for
   *     irreversible actions; user must explicitly Tab to Confirm.
   *   - On close, focus returns to the element that triggered the dialog.
   *   - role="dialog" + aria-modal="true" + aria-labelledby for SR.
   *   - Returns Promise<boolean>: true=Confirm, false=Cancel/Esc.
   * ─────────────────────────────────────────────────────────────── */
  function confirmDialog(opts) {
    return new Promise(function(resolve) {
      var prevFocus = document.activeElement;

      var backdrop = document.createElement('div');
      backdrop.setAttribute('role', 'dialog');
      backdrop.setAttribute('aria-modal', 'true');
      backdrop.style.cssText =
        'position:fixed;inset:0;background:rgba(15,23,42,.55);' +
        'z-index:10050;display:flex;align-items:center;justify-content:center;' +
        'padding:16px;animation:rfDialogFadeIn 120ms ease-out;';

      var card = document.createElement('div');
      card.style.cssText =
        'background:#fff;border-radius:14px;max-width:480px;width:100%;' +
        'box-shadow:0 20px 60px rgba(0,0,0,.25);overflow:hidden;' +
        'display:flex;flex-direction:column;';

      var header = document.createElement('div');
      header.style.cssText =
        'padding:16px 20px;border-bottom:1px solid #e5e7eb;' +
        'display:flex;align-items:center;gap:10px;' +
        (opts.dangerous ? 'background:#fef2f2;' : 'background:#f8fafc;');

      var icon = document.createElement('i');
      icon.className = 'fa ' + (opts.dangerous ? 'fa-exclamation-triangle' : 'fa-question-circle');
      icon.style.cssText = 'font-size:20px;color:' + (opts.dangerous ? '#dc2626' : '#0f766e') + ';';
      header.appendChild(icon);

      var title = document.createElement('h4');
      title.id = 'rfConfirmTitle' + Date.now();
      title.style.cssText = 'margin:0;font-size:16px;font-weight:600;color:#0f172a;';
      title.textContent = opts.title || 'Please confirm';
      backdrop.setAttribute('aria-labelledby', title.id);
      header.appendChild(title);

      var body = document.createElement('div');
      body.style.cssText = 'padding:18px 20px;color:#334155;font-size:14px;line-height:1.55;white-space:pre-wrap;';
      body.textContent = opts.body || ''; // textContent — no HTML parsing.

      var footer = document.createElement('div');
      footer.style.cssText =
        'padding:12px 16px;border-top:1px solid #e5e7eb;background:#f8fafc;' +
        'display:flex;gap:8px;justify-content:flex-end;';

      var btnCancel = document.createElement('button');
      btnCancel.type = 'button';
      btnCancel.textContent = opts.cancelText || 'Cancel';
      btnCancel.style.cssText =
        'padding:8px 16px;border-radius:8px;border:1px solid #cbd5e1;' +
        'background:#fff;color:#475569;font-weight:500;cursor:pointer;font-size:14px;';

      var btnConfirm = document.createElement('button');
      btnConfirm.type = 'button';
      btnConfirm.textContent = opts.confirmText || 'Confirm';
      btnConfirm.style.cssText =
        'padding:8px 16px;border-radius:8px;border:none;' +
        'color:#fff;font-weight:600;cursor:pointer;font-size:14px;' +
        'background:' + (opts.dangerous ? '#dc2626' : '#0f766e') + ';';

      // `infoOnly: true` hides Cancel and turns the dialog into a
      // non-actionable explanation surface (used when the admin can't
      // take any action from this dialog — e.g. the stale-allocation
      // branch where wallet-credit was the old fallback but is gone
      // post-Phase-9). Only the Confirm/OK button is rendered.
      if (!opts.infoOnly) footer.appendChild(btnCancel);
      footer.appendChild(btnConfirm);
      card.appendChild(header);
      card.appendChild(body);
      card.appendChild(footer);
      backdrop.appendChild(card);
      document.body.appendChild(backdrop);

      function cleanup() {
        document.removeEventListener('keydown', onKey, true);
        if (backdrop.parentNode) backdrop.parentNode.removeChild(backdrop);
        if (prevFocus && typeof prevFocus.focus === 'function') {
          try { prevFocus.focus(); } catch (_) {}
        }
      }
      function done(value) { cleanup(); resolve(value); }

      function onKey(e) {
        if (e.key === 'Escape') {
          e.preventDefault(); e.stopPropagation();
          done(false);
          return;
        }
        if (e.key === 'Tab') {
          // In info-only mode there's only one button — short-circuit
          // tab so focus stays on it. Otherwise cycle Cancel ↔ Confirm.
          if (opts.infoOnly) {
            e.preventDefault(); btnConfirm.focus();
            return;
          }
          if (e.shiftKey && document.activeElement === btnCancel) {
            e.preventDefault(); btnConfirm.focus();
          } else if (!e.shiftKey && document.activeElement === btnConfirm) {
            e.preventDefault(); btnCancel.focus();
          }
        }
      }
      document.addEventListener('keydown', onKey, true);

      btnCancel.addEventListener('click', function () { done(false); });
      btnConfirm.addEventListener('click', function () { done(true); });

      // Default focus: Cancel for actionable confirms (safer for
      // irreversible refunds), Confirm/OK for info-only dialogs (so
      // Enter dismisses immediately).
      (opts.infoOnly ? btnConfirm : btnCancel).focus();
    });
  }
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

  // Phase 1.X (2026-05-09) — IST conversion enforced. Refunds are
  // accounting-sensitive; bank reconciliation + audit trails require
  // a deterministic date irrespective of the admin's browser timezone.
  // requestedDate is stored as 'Y-m-d H:i:s' (server-local, no offset)
  // so this also pins interpretation to IST regardless of source tz.
  function fmtDate(d) {
    if (!d) return '--';
    var dt = new Date(d);
    if (isNaN(dt)) return d;
    return dt.toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric', timeZone:'Asia/Kolkata' });
  }

  function fmtAmount(n) {
    n = parseFloat(n) || 0;
    return n.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function statusBadge(s) {
    var cls = {
      pending:    'fm-badge-pending',
      approved:   'fm-badge-approved',
      processing: 'fm-badge-processing',
      processed:  'fm-badge-processed',
      rejected:   'fm-badge-rejected'
    };
    var label = s.charAt(0).toUpperCase() + s.slice(1);
    return '<span class="fm-badge ' + (cls[s] || '') + '">' + label + '</span>';
  }

  // Escape user-supplied text before injecting into HTML attributes.
  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"'`]/g,
    c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;','`':'&#96;'}[c])); }

  function actionButtons(item) {
    var s = (item.status || '').toLowerCase();
    var id = esc(item.id);
    var btns = '';
    if (s === 'pending') {
      btns += '<button class="fm-btn-xs fm-btn-approve" data-action="approve" data-id="' + id + '" title="Approve"><i class="fa fa-check"></i></button>';
      btns += '<button class="fm-btn-xs fm-btn-reject"  data-action="reject"  data-id="' + id + '" title="Reject"><i class="fa fa-times"></i></button>';
    } else if (s === 'approved') {
      btns += '<button class="fm-btn-xs fm-btn-process" data-action="process" data-id="' + id + '" title="Process"><i class="fa fa-cog"></i> Process</button>';
    } else if (s === 'processing') {
      // R.7: rescue action for refunds stuck in 'processing' (crash
      // interrupted the service between status flip and mark 'processed').
      // The controller enforces the 5-min TTL so misclicks on a still-active
      // refund are rejected server-side.
      btns += '<button class="fm-btn-xs fm-btn-unstick" data-action="unstick" data-id="' + id + '" title="Unstick (rescue stuck refund)"><i class="fa fa-unlock"></i> Unstick</button>';
    } else if (s === 'processed' && (item.journalPosted === false || (item.journalPosted == null && !item.journalEntryId))) {
      // R.5: refund went through but the accounting journal post failed.
      // The demands are already reversed and the voucher is written — only
      // the ledger entry is missing. Clicking Retry Journal hits the
      // idempotent poster; on success the warning icon disappears.
      //
      // Phase 1.X (2026-05-09) — relaxed strict `=== false` check to also
      // catch legacy/manual refunds where journalPosted is undefined AND
      // no journalEntryId exists. Without this, a processed refund whose
      // journal write was never attempted would never surface the retry
      // option to admin.
      btns += '<button class="fm-btn-xs fm-btn-retry-journal" data-action="retry-journal" data-id="' + id + '" title="Journal post failed — click to retry"><i class="fa fa-exclamation-triangle"></i> Retry Journal</button>';
    }
    btns += '<button class="fm-btn-xs fm-btn-view" data-action="view" data-id="' + id + '" title="View"><i class="fa fa-eye"></i></button>';
    return btns;
  }

  /* ── Load Refunds ── */
  // Tracks in-flight fetch so double-clicks on filter pills don't race.
  var loadingRefunds = false;
  function loadRefunds(status) {
    if (loadingRefunds) return;
    loadingRefunds = true;
    status = status || 'all';

    var $tbody  = $('#refundsTableBody');
    var $pills  = $('.fm-pill');
    var $refBtn = $('#btnRefresh');

    $pills.prop('disabled', true).addClass('fm-disabled');
    $refBtn.prop('disabled', true);
    var refOriginal = $refBtn.html();
    $refBtn.html('<i class="fa fa-spinner fa-spin"></i>');

    // Preserve scroll so Approve/Reject/Process don't jump the user to the
    // top after a redraw.
    var preservedScroll = window.scrollY;

    var skeleton = '';
    for (var k = 0; k < 5; k++) {
      skeleton += '<tr class="fm-skel-row"><td colspan="9"><div class="fm-skel"></div></td></tr>';
    }
    $tbody.html(skeleton);

    ajaxPost('fee_management/fetch_refunds', { status: status }, function(err, res) {
      loadingRefunds = false;
      $pills.prop('disabled', false).removeClass('fm-disabled');
      $refBtn.prop('disabled', false).html(refOriginal);

      if (err || !res || (res.status !== 'success' && res.success !== true)) {
        // Use .text() via jQuery so error strings can't inject HTML.
        var $errRow = $('<tr class="fm-empty-row"><td colspan="9"><div class="fm-empty-state"><i class="fa fa-exclamation-triangle"></i><p></p></div></td></tr>');
        $errRow.find('p').text(err || 'Failed to load refunds');
        $tbody.empty().append($errRow);
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
        // Contextual empty state so the user knows whether to change the
        // filter or create their first refund.
        var emptyMsg = ({
          all:        'No refund records yet. Click "New Refund" to create one.',
          pending:    'No refunds awaiting approval.',
          approved:   'No approved refunds waiting to be processed.',
          processing: 'No refunds currently in-flight. If any appear and stay here >5 min, use Unstick.',
          processed:  'No processed refunds in the history.',
          rejected:   'No rejected refunds.'
        }[status]) || 'No refund records found.';
        var $empty = $('<tr class="fm-empty-row"><td colspan="9"><div class="fm-empty-state"><i class="fa fa-inbox"></i><p></p></div></td></tr>');
        $empty.find('p').text(emptyMsg);
        $tbody.empty().append($empty);
        return;
      }

      var html = '';
      for (var i = 0; i < items.length; i++) {
        var r = items[i];
        html += '<tr>'
          + '<td>' + (i + 1) + '</td>'
          + '<td>' + esc(fmtDate(r.date)) + '</td>'
          + '<td class="fm-text-name">' + esc(r.student_name || '--') + '</td>'
          + '<td>' + esc(r.class_section || '--') + '</td>'
          + '<td>' + esc(r.fee_title || '--') + '</td>'
          + '<td><code class="fm-code">' + esc(r.receipt_no || '--') + '</code></td>'
          + '<td class="text-right fm-text-money">' + esc(fmtAmount(r.amount)) + '</td>'
          + '<td class="text-center">' + statusBadge(r.status || 'pending') + '</td>'
          + '<td class="text-center fm-actions">' + actionButtons(r) + '</td>'
          + '</tr>';
      }
      $tbody.html(html);
      window.scrollTo({ top: preservedScroll, behavior: 'instant' });
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
      class:         $('#rfClassHidden').val(),    // explicit — don't parse class_section on the server
      section:       $('#rfSectionHidden').val(),
      class_section: $('#rfClassSection').val(),   // kept for backward compat; controller prefers class+section
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
      if (err || !res || (res.status !== 'success' && res.success !== true)) {
        showToast(err || res.message || 'Failed to create refund', 'error');
        return;
      }
      showToast('Refund request created successfully', 'success');
      $('#createRefundForm')[0].reset();
      $('#rfStudentName, #rfClassSection, #rfClassHidden, #rfSectionHidden').val('');
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
      $('#rfStudentName, #rfClassSection, #rfClassHidden, #rfSectionHidden').val('');
      return;
    }
    $hint.text('Looking up...').removeClass('fm-hint-ok fm-hint-err');

    ajaxPost('fees/lookup_student', { user_id: uid }, function(err, res) {
      if (err || !res || !res.name) {
        $hint.text('Student not found').addClass('fm-hint-err').removeClass('fm-hint-ok');
        $('#rfStudentName, #rfClassSection, #rfClassHidden, #rfSectionHidden').val('');
        return;
      }
      $hint.text('Found').addClass('fm-hint-ok').removeClass('fm-hint-err');
      var cls = res.class   || '';
      var sec = res.section || '';
      $('#rfStudentName').val(res.name);
      $('#rfClassSection').val(cls + (sec ? ' / ' + sec : ''));
      $('#rfClassHidden').val(cls);
      $('#rfSectionHidden').val(sec);
    });
  }

  /* ── Approve / Reject — spinner + confirm + prevent double-submit ── */
  // Reusable lifecycle action that:
  //   1. Asks for confirmation
  //   2. Disables the button + shows spinner
  //   3. Hits the endpoint
  //   4. Toasts the result and refreshes the list on success
  function doLifecycleAction(opts) {
    // Stage B2: replaced native confirm() with custom dialog. Continuation
    // closure keeps the original behaviour identical post-confirmation;
    // only the prompt itself is replaced.
    var continueAction = function() {
      var $btn = opts.button;
      if ($btn && $btn.length) {
        if ($btn.data('pending')) return; // already in flight
        $btn.data('pending', true);
        $btn.data('original-html', $btn.html());
        $btn.prop('disabled', true).html('<i class="fa fa-spinner fa-spin"></i>');
      }
      ajaxPost(opts.url, opts.payload, function(err, res) {
        if ($btn && $btn.length) {
          $btn.prop('disabled', false).html($btn.data('original-html') || $btn.html());
          $btn.data('pending', false);
        }
        if (err || !res || (res.status !== 'success' && res.success !== true)) {
          showToast(err || (res && res.message) || opts.failMsg || 'Action failed', 'error');
          return;
        }
        showToast(opts.successMsg || 'Done', 'success');
        // Defensive: clear the list-load guard before refresh so any in-flight
        // or stuck fetch doesn't silently drop the reload.
        loadingRefunds = false;
        loadRefunds(activeFilter);
      });
    };

    if (opts.confirmMsg) {
      confirmDialog({
        title: opts.confirmTitle || 'Confirm action',
        body: opts.confirmMsg,
        confirmText: opts.confirmText || 'Confirm',
        cancelText: 'Cancel',
        dangerous: !!opts.dangerous
      }).then(function(ok) { if (ok) continueAction(); });
    } else {
      continueAction();
    }
  }

  window.approveRefund = function(id, $btn) {
    doLifecycleAction({
      url: 'fee_management/approve_refund',
      payload: { refund_id: id },
      confirmTitle: 'Approve refund?',
      confirmMsg: 'The refund will be marked approved and queued for processing. You can still cancel before payment is released.',
      confirmText: 'Approve',
      successMsg: 'Refund approved.',
      failMsg: 'Approval failed.',
      button: $btn,
    });
  };

  window.rejectRefund = function(id, $btn) {
    doLifecycleAction({
      url: 'fee_management/reject_refund',
      payload: { refund_id: id },
      confirmTitle: 'Reject refund?',
      confirmMsg: 'This refund will be marked rejected and cannot be processed afterwards.',
      confirmText: 'Reject',
      dangerous: true,
      successMsg: 'Refund rejected.',
      failMsg: 'Rejection failed.',
      button: $btn,
    });
  };

  window.retryJournal = function(id, $btn) {
    doLifecycleAction({
      url: 'fee_management/retry_refund_journal',
      payload: { refund_id: id },
      confirmTitle: 'Retry accounting journal?',
      confirmMsg:
        'The refund itself is already complete (demands reversed, voucher issued). This only re-attempts the ledger entry.\n\n' +
        'Safe to click repeatedly — the poster is idempotent and will not create a duplicate journal.',
      confirmText: 'Retry journal',
      successMsg: 'Journal posted.',
      failMsg: 'Journal retry failed.',
      button: $btn,
    });
  };

  window.unstickRefund = function(id, $btn) {
    doLifecycleAction({
      url: 'fee_management/unstick_refund',
      payload: { refund_id: id },
      confirmTitle: 'Rescue stuck refund?',
      confirmMsg:
        'Use ONLY if a prior processing attempt crashed and this refund has been stuck in "processing" for more than 5 minutes.\n\n' +
        'The refund will be reset to "approved" so you can click Process again. The server will refuse if the refund is still actively being processed.',
      confirmText: 'Reset to approved',
      dangerous: true,
      successMsg: 'Refund reset to approved. Click Process to retry.',
      failMsg: 'Could not unstick refund.',
      button: $btn,
    });
  };

  /* ── Process Modal ── */
  window.showProcessModal = function(id) {
    var item = refundsCache.find(function(r) { return r.id === id; });
    if (!item) return;
    // Reset every field before populating so nothing leaks from a prior
    // refund the user was looking at.
    $('#processRefundId').val(id);
    $('#pmStudentName').text(item.student_name || '--');
    $('#pmClass').text(item.class_section || '--');
    $('#pmFeeTitle').text(item.fee_title || '--');
    $('#pmReceiptNo').text(item.receipt_no || '--');
    $('#pmAmount').text(fmtAmount(item.amount));
    $('#pmReason').text(item.reason || '--');
    $('#pmRefundMode').val('');
    $('#pmRemarks').val('');
    // Ensure the button isn't stuck in "pending" from a prior cancelled run.
    $('#btnProcessRefund').data('pending', false)
        .prop('disabled', false)
        .html('<i class="fa fa-check"></i> Process Refund');
    $('#processModal').modal('show');
  };

  function processRefund() {
    var $btn = $('#btnProcessRefund');
    if ($btn.data('pending')) return; // prevent double-submit

    var id   = $('#processRefundId').val();
    var mode = $('#pmRefundMode').val();
    if (!id) { showToast('Refund ID is missing. Reopen the dialog.', 'error'); return; }
    if (!mode) { showToast('Please select a refund mode', 'error'); return; }

    // Destructive: explicit confirmation before money is moved.
    // Stage B2: replaced native confirm() with the focus-managed dialog.
    var item   = refundsCache.find(function(r){ return r.id === id; });
    var amount = item ? fmtAmount(item.amount) : '';
    var name   = item ? (item.student_name || '') : '';
    confirmDialog({
      title: 'Process refund?',
      body: 'Student: ' + name + '\n' +
            'Amount:  ' + amount + '\n' +
            'Mode:    ' + mode + '\n\n' +
            'This will reverse the original allocations and post an accounting journal. It cannot be undone from the UI.',
      confirmText: 'Process refund',
      dangerous: true
    }).then(function(ok) {
      if (!ok) return;
      $btn.data('pending', true)
          .prop('disabled', true)
          .html('<i class="fa fa-spinner fa-spin"></i> Processing...');
      submitProcessRefund(id, mode);
    });
  }

  // The actual POST. Originally split out so the stale-allocation
  // override path (R.6) could retry with `acknowledge_stale=1`. C6
  // cleanup 2026-05-10: that override is gone post-Phase-9 — the
  // server ignores the flag, the wallet-credit fallback dialog has
  // been replaced with a non-actionable info dialog (Stage B2), and
  // `submitProcessRefund` is now only ever called once per refund.
  // The function is still split out so future test hooks can stub it.
  function submitProcessRefund(id, mode) {
    var $btn = $('#btnProcessRefund');
    ajaxPost('fee_management/process_refund', {
      refund_id:   id,
      refund_mode: mode,
      remarks:     $('#pmRemarks').val().trim()
    }, function(err, res) {
      $btn.data('pending', false)
          .prop('disabled', false)
          .html('<i class="fa fa-check"></i> Process Refund');

      // R.6 (REWRITTEN 2026-05-10): stale-allocation branch.
      //
      // Pre-Phase-9 this offered a "wallet credit" override that
      // routed the refund into studentAdvanceBalances instead of
      // reducing the demand. Phase 9 removed the wallet subsystem
      // entirely, so that override no longer has a valid backend
      // landing — calling submitProcessRefund(id, mode, true) would
      // either fail or write into a dead path.
      //
      // New behaviour: surface a non-actionable info dialog explaining
      // the conflict and the only safe remediation (reverse the newer
      // receipts first, then retry). NO second-chance "proceed"
      // option, since the system can't fulfil it.
      if (res && res.status === 'error' && res.code === 'STALE_ALLOCATION') {
        var conflicts = Array.isArray(res.conflicts) ? res.conflicts : [];
        var supers    = Array.isArray(res.superseded_by) ? res.superseded_by : [];
        var detail = conflicts.map(function(c) {
          return '  • ' + (c.period || c.demand_id || '(demand)') +
                 '  (superseded by: #' + (c.superseded_by || []).join(', #') + ')';
        }).join('\n') || '  (see server log)';
        var msg =
          'This receipt cannot be refunded directly — some of its demands have been re-paid by newer receipt(s): #' + supers.join(', #') + '.\n\n' +
          'Conflicting demands:\n' + detail + '\n\n' +
          'To refund this receipt, first reverse the newer receipt(s) above, then return here and click Process again.';
        confirmDialog({
          title: 'Cannot refund — conflicting newer receipts',
          body: msg,
          confirmText: 'Got it',
          infoOnly: true,
          dangerous: true
        });
        showToast('Refund blocked by stale allocation. Reverse the newer receipt(s) first.', 'error');
        return;
      }

      if (err || !res || (res.status !== 'success' && res.success !== true)) {
        showToast(err || (res && res.message) || 'Processing failed', 'error');
        return;
      }
      // Journal-post may have failed even though the refund succeeded
      // (R.5). Surface a warning so the admin notices the Retry Journal
      // button on the row.
      if (res.journal_posted === false) {
        showToast(res.message || 'Refund processed — journal post failed.', 'error');
      } else {
        showToast('Refund processed successfully', 'success');
      }
      $('#processModal').modal('hide');

      // Jump to the "All" filter + the "Processed" pill highlight so the
      // just-processed row is guaranteed to be visible with its new green
      // badge (would disappear from view if the user was on "Approved").
      // Small delay gives Firestore a moment to propagate the status flip
      // before we re-query — otherwise we can fetch a stale "approved" read.
      loadingRefunds = false; // defensive: clear any stuck guard
      setTimeout(function() {
        filterByStatus('processed');
      }, 400);
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
  // Delegated handlers for the action buttons (rows are re-rendered on
  // every refresh, so listeners must attach at tbody level).
  $('#refundsTableBody').on('click', '[data-action]', function() {
    var $btn = $(this);
    var action = $btn.data('action');
    var id     = $btn.data('id');
    if (!id) return;
    if      (action === 'approve')        approveRefund(id, $btn);
    else if (action === 'reject')         rejectRefund(id, $btn);
    else if (action === 'process')        showProcessModal(id);
    else if (action === 'unstick')        unstickRefund(id, $btn);
    else if (action === 'retry-journal')  retryJournal(id, $btn);
    else if (action === 'view')           showDetails(id);
  });

  $('.fm-pill').on('click', function() {
    if ($(this).prop('disabled') || $(this).hasClass('fm-disabled')) return;
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
    $('#rfStudentName, #rfClassSection, #rfClassHidden, #rfSectionHidden').val('');
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
.fm-btn-unstick { background: rgba(234,179,8,.14); color: #b45309; }
.fm-btn-unstick:hover { background: rgba(234,179,8,.26); }
.fm-btn-retry-journal { background: rgba(224,92,111,.12); color: #c1354a; }
.fm-btn-retry-journal:hover { background: rgba(224,92,111,.22); }
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
.fm-badge-pending    { background: rgba(217,119,6,.12); color: #d97706; }
.fm-badge-approved   { background: rgba(59,130,246,.12); color: #3b82f6; }
.fm-badge-processing { background: rgba(234,179,8,.14); color: #b45309; }
.fm-badge-processed  { background: rgba(21,128,61,.12); color: #15803d; }
.fm-badge-rejected   { background: rgba(224,92,111,.12); color: #E05C6F; }

/* ── Empty State ── */
.fm-empty-state {
  text-align: center;
  padding: 36px 16px;
  color: var(--t3);
}
.fm-empty-state i { font-size: 2rem; margin-bottom: 8px; display: block; opacity: .5; }
.fm-empty-state p { margin: 0; font-size: .82rem; }

/* ── Loading skeleton rows ── */
.fm-skel-row td { padding: 10px !important; }
.fm-skel {
  height: 16px;
  border-radius: 4px;
  background: linear-gradient(90deg,
    rgba(255,255,255,.03) 0%,
    rgba(255,255,255,.08) 50%,
    rgba(255,255,255,.03) 100%);
  background-size: 200% 100%;
  animation: fm-skel-shimmer 1.2s ease-in-out infinite;
}
@keyframes fm-skel-shimmer {
  0%   { background-position: 100% 0; }
  100% { background-position: -100% 0; }
}

/* Disabled filter pills (during refresh) */
.fm-pill.fm-disabled, .fm-pill:disabled {
  opacity: .5;
  pointer-events: none;
  cursor: wait;
}

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

/* ============================================================
   READABILITY UPGRADE — the original styles were optimised for
   dense dashboards; as a transactional admin page the text was
   too small and strained the eye. These overrides bump type
   scale across the board without touching layout.
   ============================================================ */
.fm-page-title              { font-size: 1.65rem; }
.fm-page-title i            { font-size: 1.35rem; }
.fm-page-sub                { font-size: 0.95rem; }

/* Stat cards */
.fm-stat-value              { font-size: 1.75rem; }
.fm-stat-label              { font-size: 0.85rem; letter-spacing: .02em; }

/* Filter pills */
.fm-pill                    { font-size: 0.9rem;  padding: 8px 16px; }

/* Card headers + cards */
.fm-card-title              { font-size: 1.1rem; }
.fm-card-title i            { font-size: 1rem; }

/* Forms */
.fm-label                   { font-size: 0.9rem; font-weight: 600; letter-spacing: .01em; }
.fm-input, .fm-select, .fm-textarea { font-size: 0.95rem; padding: 9px 12px; }
.fm-hint-ok, .fm-hint-err,
.fm-form-hint               { font-size: 0.82rem; }
.fm-req                     { font-size: 0.9rem; }

/* Table */
.fm-table                   { font-size: 0.95rem; }
.fm-table th                { font-size: 0.8rem;  padding: 10px 12px; letter-spacing: .03em; }
.fm-table td                { font-size: 0.92rem; padding: 11px 12px; }
.fm-code                    { font-size: 0.88rem; padding: 2px 8px; }

/* Status badges */
.fm-badge                   { font-size: 0.78rem; padding: 4px 10px; font-weight: 700; letter-spacing: .02em; }

/* Action buttons in rows */
.fm-btn                     { font-size: 0.92rem; padding: 8px 14px; }
.fm-btn-sm                  { font-size: 0.82rem; padding: 6px 12px; }
.fm-btn-xs                  { font-size: 0.82rem; padding: 5px 10px; min-width: 30px; }
.fm-btn-xs i                { font-size: 0.92rem; }
.fm-actions .fm-btn-xs      { margin: 0 2px; }

/* Empty state */
.fm-empty-state i           { font-size: 2.2rem; margin-bottom: 10px; }
.fm-empty-state p           { font-size: 0.95rem; }

/* Modal */
.fm-modal-content           { font-size: 0.95rem; }
.fm-modal-header h4,
.fm-modal-header h5         { font-size: 1.15rem; }
.fm-detail-label            { font-size: 0.82rem; font-weight: 600; letter-spacing: .02em; }
.fm-detail-value            { font-size: 0.95rem; }

/* Toast */
.fm-toast                   { font-size: 0.9rem; padding: 12px 18px; }
.fm-toast i                 { font-size: 1rem; }

/* Improve status-badge contrast a hair — WCAG friendlier */
.fm-badge-pending           { background:#fff3cd; color:#7a4d00; }
.fm-badge-approved          { background:#d4e8ff; color:#0a4a99; }
.fm-badge-processing        { background:#fff0c2; color:#8a4b00; }
.fm-badge-processed         { background:#d4f5e0; color:#0d6433; }
.fm-badge-rejected          { background:#ffe0e0; color:#951a1a; }

/* Line-height breathing room across the whole page */
#refundsTableBody tr td     { line-height: 1.45; }
.fm-card                    { line-height: 1.5; }
</style>
