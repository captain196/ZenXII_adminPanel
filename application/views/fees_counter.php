<?php defined('BASEPATH') or exit('No direct script access allowed');

// PHP safety defaults
$receiptNo         = $receiptNo         ?? '1';
$serverDate        = $serverDate        ?? date('d-m-Y');
$accounts          = $accounts          ?? [];
?>

<div class="content-wrapper">
    <div class="fc-wrap">

        <!-- ══ TOP BAR ══ -->
        <div class="fc-topbar">
            <div>
                <h1 class="fc-page-title"><i class="fa fa-rupee"></i> Fee Counter</h1>
                <ol class="fc-breadcrumb">
                    <li><a href="<?= base_url() ?>"><i class="fa fa-home"></i> Dashboard</a></li>
                    <li><a href="<?= site_url('fees/fees_records') ?>">Fees</a></li>
                    <li>Fee Counter</li>
                </ol>
            </div>
            <div class="fc-receipt-badge">
                <span class="fc-receipt-label">Receipt No.</span>
                <span class="fc-receipt-num" id="topReceiptNo"><?= htmlspecialchars($receiptNo) ?></span>
            </div>
        </div>

        <!-- ══ STAT STRIP ══ -->
        <div class="fc-stat-strip">
            <div class="fc-stat fc-stat-blue">
                <div class="fc-stat-icon"><i class="fa fa-calendar-check-o"></i></div>
                <div class="fc-stat-body">
                    <div class="fc-stat-label">Total Fee</div>
                    <div class="fc-stat-val" id="statTotalFee">₹ 0.00</div>
                </div>
            </div>
            <div class="fc-stat fc-stat-green">
                <div class="fc-stat-icon"><i class="fa fa-check-circle"></i></div>
                <div class="fc-stat-body">
                    <div class="fc-stat-label">Already Paid</div>
                    <div class="fc-stat-val" id="statAlreadyPaid">₹ 0.00</div>
                </div>
            </div>
            <div class="fc-stat fc-stat-amber">
                <div class="fc-stat-icon"><i class="fa fa-gift"></i></div>
                <div class="fc-stat-body">
                    <div class="fc-stat-label">Discount</div>
                    <div class="fc-stat-val" id="statDiscount">₹ 0.00</div>
                </div>
            </div>
            <div class="fc-stat fc-stat-red">
                <div class="fc-stat-icon"><i class="fa fa-exclamation-circle"></i></div>
                <div class="fc-stat-body">
                    <div class="fc-stat-label">Due Amount</div>
                    <div class="fc-stat-val" id="statDue">₹ 0.00</div>
                </div>
            </div>
        </div>

        <!-- Alert placeholder -->
        <div id="fcAlertBox" style="display:none;"></div>

        <!-- ══ MAIN LAYOUT ══ -->
        <div class="fc-layout">

            <!-- ── LEFT ── -->
            <div class="fc-left">

                <!-- STEP 1 -->
                <div class="fc-card">
                    <div class="fc-card-head">
                        <span class="fc-step">1</span>
                        <i class="fa fa-user-circle"></i>
                        <h3>Student &amp; Receipt Details</h3>
                    </div>
                    <div class="fc-card-body">

                        <div class="fc-grid-2 fc-mb">
                            <div class="fc-field">
                                <label class="fc-label">Receipt No.</label>
                                <input type="text" id="receiptNo" class="fc-input"
                                    value="<?= htmlspecialchars($receiptNo) ?>" readonly>
                            </div>
                            <div class="fc-field">
                                <label class="fc-label">Date</label>
                                <input type="text" id="fcDate" class="fc-input"
                                    value="<?= htmlspecialchars($serverDate) ?>" readonly>
                            </div>
                        </div>

                        <div class="fc-mb">
                            <label class="fc-label">Student ID <span class="fc-req">*</span></label>
                            <div class="fc-student-row">
                                <div class="fc-id-wrap">
                                    <input type="text" id="user_id" class="fc-input"
                                        placeholder="Type ID &amp; press Enter or click Find…" autocomplete="off"
                                        spellcheck="false">
                                    <span class="fc-id-spinner" id="idSpinner" style="display:none;">
                                        <i class="fa fa-spinner fa-spin"></i>
                                    </span>
                                </div>
                                <button type="button" class="fc-btn fc-btn-amber" id="findBtn">
                                    <i class="fa fa-search"></i> Find
                                </button>
                                <button type="button" class="fc-btn fc-btn-ghost" id="openSearchBtn">
                                    <i class="fa fa-list-ul"></i> Browse
                                </button>
                            </div>
                            <div id="idFeedback" class="fc-id-feedback" style="display:none;"></div>
                        </div>

                        <div class="fc-grid-2 fc-mb">
                            <div class="fc-field">
                                <label class="fc-label">Student Name</label>
                                <input type="text" id="sname" class="fc-input" placeholder="Auto-filled after search"
                                    readonly>
                            </div>
                            <div class="fc-field">
                                <label class="fc-label">Father's Name</label>
                                <input type="text" id="fname" class="fc-input" placeholder="Auto-filled" readonly>
                            </div>
                        </div>

                        <div class="fc-grid-3">
                            <div class="fc-field">
                                <label class="fc-label">Class</label>
                                <input type="text" id="fcClass" class="fc-input" placeholder="Auto-filled" readonly>
                            </div>
                            <div class="fc-field">
                                <label class="fc-label">Section</label>
                                <input type="text" id="fcSection" class="fc-input" placeholder="Auto-filled" readonly>
                            </div>
                            <div class="fc-field">
                                <label class="fc-label">Discount Applied</label>
                                <input type="text" id="discountDisplay" class="fc-input fc-input-green" value="₹ 0.00"
                                    readonly>
                            </div>
                        </div>

                    </div>
                </div>

                <!-- STEP 2 -->
                <div class="fc-card">
                    <div class="fc-card-head">
                        <span class="fc-step">2</span>
                        <i class="fa fa-calendar"></i>
                        <h3>Select Months</h3>
                        <span class="fc-head-hint" id="monthsHint">Find a student first to see months</span>
                    </div>
                    <div class="fc-card-body">
                        <div class="fc-month-grid" id="monthGrid">
                            <div class="fc-months-placeholder" id="monthsPlaceholder">
                                <i class="fa fa-hand-o-up"></i>
                                <p>Search and select a student above to load their payment months.</p>
                            </div>
                        </div>
                        <div class="fc-month-actions" id="monthActions" style="display:none;">
                            <button type="button" class="fc-btn fc-btn-ghost fc-btn-sm" id="selectAllBtn">
                                <i class="fa fa-check-square-o"></i> Select All Unpaid
                            </button>
                            <button type="button" class="fc-btn fc-btn-ghost fc-btn-sm" id="clearAllBtn">
                                <i class="fa fa-square-o"></i> Clear
                            </button>
                            <button type="button" class="fc-btn fc-btn-primary" id="fetchDetailsBtn" disabled>
                                <i class="fa fa-refresh"></i> Fetch Fee Details
                            </button>
                        </div>
                    </div>
                </div>

                <!-- STEP 3 -->
                <div class="fc-card" id="breakdownCard" style="display:none;">
                    <div class="fc-card-head">
                        <span class="fc-step">3</span>
                        <i class="fa fa-table"></i>
                        <h3>Fee Breakdown</h3>
                        <span class="fc-head-hint" id="breakdownHeading"></span>
                        <button type="button" class="fc-btn fc-btn-ghost fc-btn-xs" id="expandBreakdownBtn"
                            style="margin-left:auto;">
                            <i class="fa fa-expand"></i> Expand
                        </button>
                    </div>
                    <div class="fc-card-body" style="padding:0;">
                        <div class="fc-table-wrap">
                            <table class="fc-table" id="breakdownTable">
                                <thead>
                                    <tr>
                                        <th>Fee Title</th>
                                        <th style="text-align:right;">Amount (₹)</th>
                                    </tr>
                                </thead>
                                <tbody id="breakdownTbody"></tbody>
                                <tfoot>
                                    <tr>
                                        <td><strong>Grand Total</strong></td>
                                        <td style="text-align:right;"><strong id="breakdownGrandTotal">0.00</strong>
                                        </td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- STEP 4 -->
                <div class="fc-card" id="paymentCard" style="display:none;">
                    <div class="fc-card-head">
                        <span class="fc-step">4</span>
                        <i class="fa fa-credit-card"></i>
                        <h3>Submit Payment</h3>
                    </div>
                    <div class="fc-card-body">

                        <div class="fc-field fc-mb">
                            <label class="fc-label" style="font-size:12px;color:var(--fc-teal);font-weight:800;">
                                <i class="fa fa-credit-card"></i>&nbsp; Mode of Payment <span class="fc-req">*</span>
                            </label>
                            <div class="fc-select-wrap">
                                <select id="accountSelect" class="fc-select fc-select-highlighted" required>
                                    <option value="" disabled selected>— Select payment mode —</option>
                                    <?php foreach ($accounts as $aName => $under): ?>
                                        <option value="<?= htmlspecialchars($aName) ?>">
                                            <?= htmlspecialchars($aName) ?> (<?= htmlspecialchars($under) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                    <?php if (empty($accounts)): ?>
                                        <option disabled>No accounts configured</option>
                                    <?php endif; ?>
                                </select>
                                <i class="fa fa-chevron-down fc-select-arrow"></i>
                            </div>
                        </div>

                        <div class="fc-grid-3 fc-mb">
                            <div class="fc-field">
                                <label class="fc-label">School Fees to Submit (₹) <span class="fc-req">*</span></label>
                                <input type="number" id="submitSchoolFees" class="fc-input fc-input-primary"
                                    placeholder="0.00" step="0.01" min="0">
                            </div>
                            <div class="fc-field">
                                <label class="fc-label">Fine Amount (₹)</label>
                                <input type="number" id="fineAmount" class="fc-input" placeholder="0.00" step="0.01"
                                    min="0">
                            </div>
                            <div class="fc-field">
                                <label class="fc-label">Reference / Remark</label>
                                <input type="text" id="reference" class="fc-input" placeholder="e.g. Cash payment">
                            </div>
                        </div>

                        <div class="fc-due-bar">
                            <div class="fc-due-item">
                                <span class="fc-due-label">Total Fee</span>
                                <span class="fc-due-val" id="barTotalFee">₹ 0.00</span>
                            </div>
                            <span class="fc-due-sep">−</span>
                            <div class="fc-due-item">
                                <span class="fc-due-label">Discount</span>
                                <span class="fc-due-val fc-green" id="barDiscount">₹ 0.00</span>
                            </div>
                            <span class="fc-due-sep">−</span>
                            <div class="fc-due-item">
                                <span class="fc-due-label">Overpaid</span>
                                <span class="fc-due-val fc-green" id="barOverpaid">₹ 0.00</span>
                            </div>
                            <span class="fc-due-sep">=</span>
                            <div class="fc-due-item fc-due-item-big">
                                <span class="fc-due-label">Due Amount</span>
                                <span class="fc-due-val fc-red" id="barDueAmount">₹ 0.00</span>
                            </div>
                        </div>

                        <!-- Quick payment buttons -->
                        <div class="fc-quick-pay" id="quickPayBtns" style="display:none;">
                            <button type="button" class="fc-btn fc-btn-primary fc-btn-sm" onclick="FC_payFull()">
                                <i class="fa fa-check-circle"></i> Pay Full Due
                            </button>
                            <button type="button" class="fc-btn fc-btn-amber fc-btn-sm" onclick="FC_payCustom()">
                                <i class="fa fa-pencil"></i> Custom Amount
                            </button>
                        </div>

                        <!-- Live allocation preview -->
                        <div class="fc-alloc-preview" id="allocPreview" style="display:none;">
                            <div class="fc-alloc-title"><i class="fa fa-list-ol"></i> Payment Allocation Preview</div>
                            <div class="fc-alloc-list" id="allocList"></div>
                            <div class="fc-alloc-advance" id="allocAdvance" style="display:none;"></div>
                        </div>

                        <div class="fc-action-bar">
                            <button type="button" class="fc-btn fc-btn-ghost"
                                onclick="location.href='<?= site_url('fees/fees_counter') ?>'">
                                <i class="fa fa-file-o"></i> New Receipt
                            </button>
                            <button type="button" id="submitFeesBtn" class="fc-btn fc-btn-submit">
                                <i class="fa fa-paper-plane"></i> Submit Fees
                            </button>
                        </div>

                    </div>
                </div>

            </div><!-- /.fc-left -->

            <!-- ── RIGHT ── -->
            <div class="fc-right">
                <div class="fc-summary-card">
                    <div class="fc-summary-head">
                        <i class="fa fa-file-text-o"></i> Payment Summary
                    </div>
                    <div class="fc-summary-body">
                        <div class="fc-summary-row"><span>Student</span><strong id="sumName">—</strong></div>
                        <div class="fc-summary-row"><span>Class</span><strong id="sumClass">—</strong></div>
                        <div class="fc-summary-row"><span>Receipt No.</span><strong
                                id="sumReceiptNo"><?= htmlspecialchars($receiptNo) ?></strong></div>
                        <div class="fc-summary-row"><span>Payment Mode</span><strong id="sumPaymentMode">—</strong>
                        </div>
                        <div class="fc-summary-row"><span>Date</span><strong
                                id="sumDate"><?= htmlspecialchars($serverDate) ?></strong></div>
                        <div class="fc-summary-divider"></div>
                        <div class="fc-summary-row"><span>Months Selected</span><strong id="sumMonths">—</strong></div>
                        <div class="fc-summary-row"><span>Total Fee</span><strong id="sumTotal">₹ 0.00</strong></div>
                        <div class="fc-summary-row fc-green"><span>Discount</span><strong id="sumDiscountRow">₹
                                0.00</strong></div>
                        <div class="fc-summary-row fc-green"><span>Overpaid (carry-fwd)</span><strong id="sumOverpaid">₹
                                0.00</strong></div>
                        <div class="fc-summary-divider"></div>
                        <div class="fc-summary-row fc-summary-due"><span>DUE AMOUNT</span><strong id="sumDue">₹
                                0.00</strong></div>
                        <div class="fc-summary-divider"></div>
                        <div class="fc-summary-row"><span>Fine</span><strong id="sumFine">₹ 0.00</strong></div>
                        <div class="fc-summary-row fc-summary-payable"><span>Submitting Now</span><strong
                                id="sumPayable">₹ 0.00</strong></div>
                    </div>
                    <div class="fc-summary-history" id="historyBtnWrap" style="display:none;">
                        <button type="button" class="fc-btn fc-btn-history-full" id="feesRecordBtn">
                            <i class="fa fa-history"></i> View Payment History
                        </button>
                    </div>
                </div>
            </div>

        </div><!-- /.fc-layout -->
    </div><!-- /.fc-wrap -->
</div><!-- /.content-wrapper -->


<!-- ══ MODAL: Search ══ -->
<div class="fc-overlay" id="searchModal">
    <div class="fc-modal">
        <div class="fc-modal-head">
            <h4><i class="fa fa-search"></i> Browse Students</h4>
            <button class="fc-modal-close" onclick="closeModal('searchModal')">&times;</button>
        </div>
        <div class="fc-modal-body">
            <div class="fc-search-box">
                <input type="text" class="fc-input" id="searchInput"
                    placeholder="Search by name, ID, father name or class…" autocomplete="off">
                <button type="button" class="fc-btn fc-btn-primary" id="doSearchBtn">
                    <i class="fa fa-search"></i> Search
                </button>
            </div>
            <div class="fc-table-wrap" style="margin-top:12px;">
                <table class="fc-table">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>User ID</th>
                            <th>Name</th>
                            <th>Father Name</th>
                            <th>Class</th>
                            <th>Section</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody id="searchResults">
                        <tr>
                            <td colspan="7" class="fc-empty-cell">Enter a term and click Search.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ══ MODAL: Expand ══ -->
<div class="fc-overlay" id="expandModal">
    <div class="fc-modal fc-modal-wide">
        <div class="fc-modal-head">
            <h4><i class="fa fa-table"></i> Detailed Fee Breakdown</h4>
            <button class="fc-modal-close" onclick="closeModal('expandModal')">&times;</button>
        </div>
        <div class="fc-modal-body" id="expandModalBody">
            <p style="text-align:center;color:var(--fc-muted);padding:30px 0;">Fetch fee details first.</p>
        </div>
    </div>
</div>

<!-- ══ MODAL: History ══ -->
<div class="fc-overlay" id="historyModal">
    <div class="fc-modal">
        <div class="fc-modal-head">
            <h4><i class="fa fa-history"></i> Payment History</h4>
            <button class="fc-modal-close" onclick="closeModal('historyModal')">&times;</button>
        </div>
        <div class="fc-modal-body">
            <div class="fc-table-wrap">
                <table class="fc-table">
                    <thead>
                        <tr>
                            <th>Receipt</th>
                            <th>Date</th>
                            <th style="text-align:right;">Amount</th>
                            <th style="text-align:right;">Fine</th>
                            <th style="text-align:right;">Discount</th>
                            <th>Mode</th>
                        </tr>
                    </thead>
                    <tbody id="historyTbody">
                        <tr>
                            <td colspan="6" class="fc-empty-cell">No history loaded yet.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ══ MODAL: Confirm Payment ══ -->
<div class="fc-overlay" id="confirmModal">
    <div class="fc-modal" style="max-width:480px;">
        <div class="fc-modal-head" style="background:linear-gradient(135deg,var(--fc-teal),#134e4a);">
            <h4 style="color:#fff"><i class="fa fa-shield"></i> Confirm Payment</h4>
            <button class="fc-modal-close" onclick="closeModal('confirmModal')" style="color:#fff">&times;</button>
        </div>
        <div class="fc-modal-body" id="confirmBody" style="padding:20px;"></div>
        <div style="display:flex;gap:10px;justify-content:flex-end;padding:14px 20px;border-top:1px solid var(--fc-border);">
            <button class="fc-btn fc-btn-ghost" onclick="closeModal('confirmModal')">Cancel</button>
            <button class="fc-btn fc-btn-submit" id="confirmSubmitBtn"><i class="fa fa-paper-plane"></i> Confirm & Submit</button>
        </div>
    </div>
</div>

<!-- ══ MODAL: Payment Success ══ -->
<div class="fc-overlay" id="successModal">
    <div class="fc-modal" style="max-width:440px;">
        <div class="fc-modal-body" style="text-align:center;padding:30px 24px;">
            <div style="width:64px;height:64px;border-radius:50%;background:var(--fc-sky);display:inline-flex;align-items:center;justify-content:center;margin-bottom:14px;">
                <i class="fa fa-check-circle" style="font-size:32px;color:var(--fc-teal);"></i>
            </div>
            <h3 style="margin:0 0 6px;font-size:18px;color:var(--fc-navy);">Payment Successful</h3>
            <p id="successMsg" style="font-size:13px;color:var(--fc-muted);margin:0 0 16px;"></p>
            <div id="successDetail" style="text-align:left;font-size:13px;background:var(--fc-sky);border-radius:8px;padding:14px;margin-bottom:16px;"></div>
            <div style="display:flex;gap:10px;justify-content:center;">
                <a id="successPrintBtn" href="#" target="_blank" class="fc-btn fc-btn-primary"><i class="fa fa-print"></i> Print Receipt</a>
                <button class="fc-btn fc-btn-ghost" onclick="closeModal('successModal');location.href='<?= site_url('fees/fees_counter') ?>'"><i class="fa fa-file-o"></i> New Receipt</button>
            </div>
        </div>
    </div>
</div>

<div id="fcToastWrap" class="fc-toast-wrap"></div>

<script>
    /* ================================================================
   fees_counter.php — JS (FIXED: Class/Section separation)
   SECURITY: Every POST sends CSRF token TWO ways:
     1. FormData field  CSRF_NAME=CSRF_HASH  → CI built-in filter
     2. X-CSRF-Token header                  → MY_Controller check
   GET requests are read-only and need no CSRF token.
================================================================ */

    /* ── CSRF from meta tags (written by include/header.php) ── */
    var CSRF_NAME = document.querySelector('meta[name="csrf-name"]').getAttribute('content');
    var CSRF_HASH = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    /* ── App State ── */
    var FC = {
        userId: '',
        studentName: '',
        fatherName: '',
        className: '', // e.g. "Class 8th"
        sectionName: '', // e.g. "Section B"
        discountAmt: 0,
        overpaidAmt: 0,
        grandTotal: 0,
        selectedMonths: [],
        monthFeeMap: {},
        alreadyPaid: 0
    };

    var SITE_URL = '<?= rtrim(site_url(), '/') ?>';
    var RECEIPT_NO = '<?= htmlspecialchars($receiptNo) ?>';

    /* ── Helpers ── */
    function fmtRs(n) {
        return '₹ ' + parseFloat(n || 0).toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function fmtNum(n) {
        return parseFloat(n || 0).toLocaleString('en-IN', {
            minimumFractionDigits: 2,
            maximumFractionDigits: 2
        });
    }

    function openModal(id) {
        document.getElementById(id).classList.add('open');
    }

    function closeModal(id) {
        document.getElementById(id).classList.remove('open');
    }

    /**
     * Returns a combined display string like "Class 8th Section B"
     * Handles cases where either value might be empty.
     */
    function getDisplayClassSection() {
        var parts = [];
        if (FC.className) parts.push(FC.className);
        if (FC.sectionName) parts.push(FC.sectionName);
        return parts.length ? parts.join(' ') : '';
    }

    document.querySelectorAll('.fc-overlay').forEach(function(ov) {
        ov.addEventListener('click', function(e) {
            if (e.target === ov) ov.classList.remove('open');
        });
    });

    function showToast(msg, type) {
        var wrap = document.getElementById('fcToastWrap');
        var el = document.createElement('div');
        el.className = 'fc-toast fc-toast-' + (type || 'info');
        var icons = {
            success: 'check-circle',
            error: 'times-circle',
            warning: 'exclamation-triangle',
            info: 'info-circle'
        };
        el.innerHTML = '<i class="fa fa-' + (icons[type] || 'info-circle') + '"></i> ' + msg;
        wrap.appendChild(el);
        setTimeout(function() {
            el.classList.add('fc-toast-hide');
            setTimeout(function() {
                el.remove();
            }, 350);
        }, 3500);
    }

    function showAlert(msg, type, persistent) {
        var box = document.getElementById('fcAlertBox');
        box.className = 'fc-alert fc-alert-' + (type || 'info');
        box.innerHTML = '<i class="fa fa-exclamation-triangle"></i> <div style="flex:1">' + msg + '</div>'
            + '<button onclick="this.parentElement.style.display=\'none\'" style="background:none;border:none;color:inherit;font-size:18px;cursor:pointer;padding:0 4px;opacity:.7">&times;</button>';
        box.style.display = 'flex';
        if (!persistent) {
            setTimeout(function() { box.style.display = 'none'; }, 6000);
        }
    }

    /*
     * ── postForm(url, params) ─────────────────────────────────────
     * Central POST helper used by ALL state-changing requests.
     *
     * Sends CSRF token in BOTH ways so every layer is satisfied:
     *   • FormData field:   CSRF_NAME = CSRF_HASH
     *     → CI's csrf_protection filter reads $_POST[CSRF_NAME]
     *       and removes it before the controller runs. ✓
     *   • Header:          X-CSRF-Token: CSRF_HASH
     *     → MY_Controller's constructor reads get_request_header()
     *       as a second validation layer. ✓
     *
     * No bypasses. No exclusions. Both layers pass on every call.
     * ────────────────────────────────────────────────────────────
     */
    function postForm(url, params) {
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH); /* layer 1: CI built-in filter */
        if (params) {
            Object.keys(params).forEach(function(k) {
                var v = params[k];
                if (Array.isArray(v)) {
                    v.forEach(function(item) {
                        fd.append(k, item);
                    });
                } else {
                    fd.append(k, v);
                }
            });
        }
        return fetch(url, {
                method: 'POST',
                body: fd,
                headers: {
                    'X-CSRF-Token': CSRF_HASH,
                    /* layer 2: MY_Controller */
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            });
    }

    /*
     * ── postJSON(url, payload) ────────────────────────────────────
     * Used ONLY for fetch_fee_receipts which reads php://input JSON.
     * CI's built-in filter cannot read JSON bodies, so we send the
     * token in the header only. MY_Controller validates it there.
     * This endpoint is read-only (no money changes), so header-only
     * CSRF is acceptable.
     * ────────────────────────────────────────────────────────────
     */
    function postJSON(url, payload) {
        return fetch(url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CSRF_HASH,
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            })
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            });
    }

    /* ── Recalc ── */
    function recalc() {
        var fine = parseFloat(document.getElementById('fineAmount').value) || 0;
        var schoolFee = parseFloat(document.getElementById('submitSchoolFees').value) || 0;
        var due = Math.max(0, FC.grandTotal - FC.discountAmt - FC.overpaidAmt);

        // Use combined class+section for display everywhere
        var displayClassSection = getDisplayClassSection();

        document.getElementById('statTotalFee').textContent = fmtRs(FC.grandTotal);
        document.getElementById('statAlreadyPaid').textContent = fmtRs(FC.alreadyPaid);
        document.getElementById('statDiscount').textContent = fmtRs(FC.discountAmt);
        document.getElementById('statDue').textContent = fmtRs(due);

        document.getElementById('barTotalFee').textContent = fmtRs(FC.grandTotal);
        document.getElementById('barDiscount').textContent = fmtRs(FC.discountAmt);
        document.getElementById('barOverpaid').textContent = fmtRs(FC.overpaidAmt);
        document.getElementById('barDueAmount').textContent = fmtRs(due);

        document.getElementById('sumName').textContent = FC.studentName || '—';
        document.getElementById('sumClass').textContent = displayClassSection || '—';
        document.getElementById('sumMonths').textContent = FC.selectedMonths.length ? FC.selectedMonths.join(', ') : '—';
        document.getElementById('sumTotal').textContent = fmtRs(FC.grandTotal);
        document.getElementById('sumDiscountRow').textContent = fmtRs(FC.discountAmt);
        document.getElementById('sumOverpaid').textContent = fmtRs(FC.overpaidAmt);
        document.getElementById('sumDue').textContent = fmtRs(due);
        document.getElementById('sumFine').textContent = fmtRs(fine);
        document.getElementById('sumPayable').textContent = fmtRs(schoolFee + fine);
        document.getElementById('discountDisplay').value = '₹ ' + fmtNum(FC.discountAmt);
    }

    /* ── Allocation Preview ── */
    function updateAllocationPreview() {
        var amt = parseFloat(document.getElementById('submitSchoolFees').value) || 0;
        var container = document.getElementById('allocPreview');
        var list = document.getElementById('allocList');
        var advEl = document.getElementById('allocAdvance');
        var qpEl = document.getElementById('quickPayBtns');

        if (!FC.selectedMonths.length || amt <= 0 || !Object.keys(FC.monthFeeMap).length) {
            container.style.display = 'none';
            return;
        }

        container.style.display = '';
        if (qpEl) qpEl.style.display = '';
        var remaining = amt;
        var html = '';

        FC.selectedMonths.forEach(function(m) {
            var monthDue = parseFloat(FC.monthFeeMap[m]) || 0;
            if (monthDue <= 0) return;
            var allocated = Math.min(remaining, monthDue);
            remaining -= allocated;
            var isCleared = (allocated >= monthDue - 0.01);
            var cls = isCleared ? 'cleared' : 'partial';
            var tag = isCleared
                ? '<span class="alloc-tag cleared-tag">Cleared</span>'
                : '<span class="alloc-tag partial-tag">Partial</span>';
            html += '<div class="fc-alloc-item ' + cls + '">'
                + '<span class="alloc-month">' + m + '</span>'
                + '<span class="alloc-amount">₹ ' + fmtNum(allocated) + ' / ' + fmtNum(monthDue) + '</span>'
                + tag + '</div>';
        });
        list.innerHTML = html;

        if (remaining > 0.01) {
            advEl.innerHTML = '<i class="fa fa-info-circle"></i> ₹ ' + fmtNum(remaining) + ' will be stored as advance (overpayment).';
            advEl.style.display = '';
        } else {
            advEl.style.display = 'none';
        }
    }

    function FC_payFull() {
        var due = Math.max(0, FC.grandTotal - FC.discountAmt - FC.overpaidAmt);
        document.getElementById('submitSchoolFees').value = due.toFixed(2);
        recalc();
        updateAllocationPreview();
    }

    function FC_payCustom() {
        var el = document.getElementById('submitSchoolFees');
        el.value = '';
        el.focus();
        el.select();
    }

    /* ── Month Tiles ── */
    function buildMonthTiles(monthFees) {
        var grid = document.getElementById('monthGrid');
        var actions = document.getElementById('monthActions');
        var hint = document.getElementById('monthsHint');
        var ph = document.getElementById('monthsPlaceholder');

        grid.innerHTML = '';
        if (ph) ph.style.display = 'none';

        var months = ['April', 'May', 'June', 'July', 'August', 'September',
            'October', 'November', 'December', 'January', 'February', 'March', 'Yearly Fees'
        ];
        var unpaid = 0;

        months.forEach(function(m) {
            var paid = monthFees[m] === 1;
            if (!paid) unpaid++;

            var tile = document.createElement('div');
            tile.className = 'fc-month-tile' + (paid ? ' paid' : '');
            tile.dataset.month = m;
            tile.innerHTML =
                '<div class="fc-month-name">' + m + '</div>' +
                '<div class="fc-month-status">' +
                (paid ? '<i class="fa fa-check-circle" style="color:#16a34a"></i> Paid' :
                    '<i class="fa fa-circle-o"></i> Unpaid') +
                '</div>';

            if (!paid) {
                tile.addEventListener('click', function() {
                    tile.classList.toggle('selected');
                    updateFromTiles();
                });
            }
            grid.appendChild(tile);
        });

        if (actions) actions.style.display = 'flex';
        if (hint) hint.textContent = unpaid + ' unpaid month(s)';
        updateFromTiles();
    }

    function updateFromTiles() {
        var sel = Array.from(document.querySelectorAll('.fc-month-tile.selected')).map(function(t) {
            return t.dataset.month;
        });
        FC.selectedMonths = sel;
        var btn = document.getElementById('fetchDetailsBtn');
        if (btn) btn.disabled = sel.length === 0;
        document.getElementById('sumMonths').textContent = sel.length ? sel.join(', ') : '—';
    }

    /* ── Fetch Months ── */
    function fetchMonths(userId) {
        if (!userId) return;
        postForm(SITE_URL + '/fees/fetch_months', {
                user_id: userId
            })
            .then(function(data) {
                if (data.error) {
                    showAlert('Month load failed: ' + data.error, 'warning');
                    return;
                }
                buildMonthTiles(data);
                showToast('Months loaded', 'info');
            })
            .catch(function(e) {
                console.error('fetchMonths:', e);
                showAlert('Could not load months. Please try again.', 'error');
            });
    }

    /* ── Student Lookup ── */
    function lookupStudentById() {
        var uid = document.getElementById('user_id').value.trim();
        if (!uid) {
            showAlert('Please enter a Student ID first.', 'warning');
            return;
        }

        document.getElementById('idSpinner').style.display = 'inline';
        document.getElementById('findBtn').disabled = true;

        var fb = document.getElementById('idFeedback');
        fb.style.display = 'none';
        fb.className = 'fc-id-feedback';

        postForm(SITE_URL + '/fees/lookup_student', {
                user_id: uid
            })
            .then(function(data) {
                document.getElementById('idSpinner').style.display = 'none';
                document.getElementById('findBtn').disabled = false;

                if (data.error) {
                    fb.className = 'fc-id-feedback fc-id-feedback-error';
                    fb.textContent = '✗ ' + data.error;
                    fb.style.display = 'block';
                    return;
                }
                selectStudent(data);
                fb.className = 'fc-id-feedback fc-id-feedback-success';
                fb.textContent = '✓ Student found: ' + data.name;
                fb.style.display = 'block';
            })
            .catch(function(e) {
                document.getElementById('idSpinner').style.display = 'none';
                document.getElementById('findBtn').disabled = false;
                console.error('lookupStudentById:', e);
                showAlert('Network error during student lookup.', 'error');
            });
    }

    /* ── Select Student ── */
    /*
     * Called from:
     *   1. lookupStudentById()  → data comes from lookup_student (has normalized class & section)
     *   2. doSearch() modal     → data comes from search_student (may have raw class, no section)
     *   3. ?uid= auto-load     → data comes from lookup_student (has normalized class & section)
     *
     * For case 2 (search modal), we now call lookupStudentById() instead of
     * selectStudent() directly, so we always get normalized class & section.
     */
    function selectStudent(s) {
        FC.userId = s.user_id || '';
        FC.studentName = s.name || '';
        FC.fatherName = s.father_name || '';
        FC.className = s.class || ''; // e.g. "Class 8th"
        FC.sectionName = s.section || ''; // e.g. "Section B"

        document.getElementById('user_id').value = FC.userId;
        document.getElementById('sname').value = FC.studentName;
        document.getElementById('fname').value = FC.fatherName;

        // Display combined class + section in the Class field
        document.getElementById('fcClass').value = FC.className;
        document.getElementById('fcSection').value = FC.sectionName;

        // Summary panel
        var displayClassSection = getDisplayClassSection();
        document.getElementById('sumName').textContent = FC.studentName;
        document.getElementById('sumClass').textContent = displayClassSection;

        // Reset fee state
        FC.grandTotal = FC.discountAmt = FC.overpaidAmt = FC.alreadyPaid = 0;
        FC.monthFeeMap = {};
        document.getElementById('breakdownCard').style.display = 'none';
        document.getElementById('paymentCard').style.display = 'none';

        var hw = document.getElementById('historyBtnWrap');
        if (hw) hw.style.display = '';

        recalc();
        fetchMonths(FC.userId);
    }

    /* ── Fetch Fee Details ── */
    function fetchFeeDetails() {
        if (!FC.userId) {
            showAlert('Please select a student first.', 'error');
            return;
        }
        if (!FC.selectedMonths.length) {
            showAlert('Please select at least one month.', 'warning');
            return;
        }

        var btn = document.getElementById('fetchDetailsBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Fetching…';

        postForm(SITE_URL + '/fees/fetch_fee_details', {
                user_id: FC.userId,
                'months[]': FC.selectedMonths
            })
            .then(function(d) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-refresh"></i> Fetch Fee Details';
                if (d.error) {
                    if (d.error === 'no_fee_structure') {
                        // Show toast notification
                        showToast('No fee structure found. Set up Fee Titles & Chart first.', 'error');
                        // Show guidance card in the breakdown area
                        document.getElementById('breakdownCard').style.display = '';
                        document.getElementById('breakdownCard').querySelector('.fc-card-body').innerHTML =
                            '<div style="text-align:center;padding:28px 20px">'
                            + '<div style="width:56px;height:56px;border-radius:50%;background:rgba(220,38,38,.08);display:inline-flex;align-items:center;justify-content:center;margin-bottom:12px">'
                            + '<i class="fa fa-exclamation-triangle" style="font-size:24px;color:#dc2626"></i></div>'
                            + '<h3 style="font-size:15px;font-weight:700;color:var(--fc-navy,#1a2940);margin:0 0 6px">No Fee Structure Found</h3>'
                            + '<p style="font-size:13px;color:var(--fc-muted,#64748b);margin:0 0 16px;max-width:360px;display:inline-block">'
                            + (d.message || 'Please set up fee titles and chart for this class/section before collecting fees.') + '</p>'
                            + '<div style="display:flex;gap:10px;justify-content:center;flex-wrap:wrap">'
                            + '<a href="' + SITE_URL + '/fee_management/categories" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:8px;background:var(--fc-teal,#0f766e);color:#fff;text-decoration:none;font-size:13px;font-weight:600">'
                            + '<i class="fa fa-list"></i> Fee Categories</a>'
                            + '<a href="' + SITE_URL + '/fees/fees_chart" style="display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:8px;border:1.5px solid var(--fc-teal,#0f766e);color:var(--fc-teal,#0f766e);background:transparent;text-decoration:none;font-size:13px;font-weight:600">'
                            + '<i class="fa fa-table"></i> Fee Chart</a>'
                            + '</div></div>';
                        document.getElementById('paymentCard').style.display = 'none';
                    } else {
                        showToast(d.message || d.error, 'error');
                    }
                    return;
                }
                applyFetchedData(d);
            })
            .catch(function(e) {
                console.error('fetchFeeDetails:', e);
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-refresh"></i> Fetch Fee Details';
                showAlert('Network error. Please try again.', 'error');
            });
    }

    function applyFetchedData(d) {
        FC.grandTotal = parseFloat(d.grandTotal) || 0;
        FC.discountAmt = parseFloat(d.discountAmount) || 0;
        FC.overpaidAmt = parseFloat(d.overpaidFees) || 0;
        FC.monthFeeMap = d.monthTotals || {};

        var tbody = document.getElementById('breakdownTbody');
        tbody.innerHTML = '';
        (d.feesRecord || []).forEach(function(row) {
            var tr = document.createElement('tr');
            tr.innerHTML = '<td>' + row.title + '</td><td style="text-align:right;font-weight:600;">' + fmtNum(row
                .total) + '</td>';
            tbody.appendChild(tr);
        });
        if (!d.feesRecord || !d.feesRecord.length)
            tbody.innerHTML = '<tr><td colspan="2" class="fc-empty-cell">No fee titles found.</td></tr>';

        document.getElementById('breakdownGrandTotal').textContent = fmtNum(FC.grandTotal);
        var h = document.getElementById('breakdownHeading');
        if (h) h.textContent = d.message || '';

        document.getElementById('breakdownCard').style.display = '';
        document.getElementById('paymentCard').style.display = '';

        var due = Math.max(0, FC.grandTotal - FC.discountAmt - FC.overpaidAmt);
        document.getElementById('submitSchoolFees').value = due.toFixed(2);

        buildExpandModal(d.feeRecord, d.selectedMonths, d.monthTotals, d.grandTotal);
        recalc();
        updateAllocationPreview();
        showToast('Fee details loaded!', 'success');
        document.getElementById('breakdownCard').scrollIntoView({
            behavior: 'smooth',
            block: 'start'
        });
    }

    function buildExpandModal(feeRecord, months, mTotals, grand) {
        var body = document.getElementById('expandModalBody');
        if (!feeRecord || !months || !months.length) {
            body.innerHTML = '<p style="text-align:center;padding:30px 0;">No breakdown data.</p>';
            return;
        }
        var html = '<div class="fc-table-wrap"><table class="fc-table"><thead><tr><th>Fee Title</th>';
        months.forEach(function(m) {
            html += '<th style="text-align:center;">' + m + '</th>';
        });
        html += '<th style="text-align:right;">Total</th></tr></thead><tbody>';
        Object.values(feeRecord).forEach(function(row) {
            html += '<tr><td>' + row.title + '</td>';
            months.forEach(function(m) {
                html += '<td style="text-align:center;">' + fmtNum(row[m] || 0) + '</td>';
            });
            html += '<td style="text-align:right;font-weight:700;">' + fmtNum(row.total) + '</td></tr>';
        });
        html += '</tbody><tfoot><tr><td><strong>Total</strong></td>';
        months.forEach(function(m) {
            html += '<td style="text-align:center;"><strong>' + fmtNum((mTotals || {})[m] || 0) + '</strong></td>';
        });
        html += '<td style="text-align:right;"><strong>' + fmtNum(grand) + '</strong></td></tr></tfoot></table></div>';
        body.innerHTML = html;
    }

    /* ── Modal Search ── */
    function doSearch() {
        var q = document.getElementById('searchInput').value.trim();
        if (!q) return;

        var tbody = document.getElementById('searchResults');
        tbody.innerHTML =
            '<tr><td colspan="7" class="fc-empty-cell"><i class="fa fa-spinner fa-spin"></i> Searching…</td></tr>';

        postForm(SITE_URL + '/fees/search_student', {
                search_name: q
            })
            .then(function(data) {
                tbody.innerHTML = '';
                if (!data || !data.length) {
                    tbody.innerHTML = '<tr><td colspan="7" class="fc-empty-cell">No students found.</td></tr>';
                    return;
                }
                data.forEach(function(s, i) {
                    var tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td>' + (i + 1) + '</td>' +
                        '<td><span class="fc-id-pill">' + (s.user_id || '—') + '</span></td>' +
                        '<td><strong>' + (s.name || '—') + '</strong></td>' +
                        '<td>' + (s.father_name || '—') + '</td>' +
                        '<td>'+(s.class||'—')+'</td>' +
                        '<td>'+(s.section||'—')+'</td>' +
                        '<td><button type="button" class="fc-btn fc-btn-primary fc-btn-xs">Select</button></td>';
                    /*
                     * FIX: search_student returns raw class like "8th" or "8th B"
                     * without normalized "Class 8th" / "Section B".
                     * Instead of calling selectStudent(s) directly, we set the
                     * user_id field and call lookupStudentById() which hits
                     * lookup_student — that returns properly normalized class
                     * and section via _resolveClassSection().
                     */
                    tr.querySelector('button').addEventListener('click', function() {
                        closeModal('searchModal');
                        document.getElementById('searchInput').value = '';
                        document.getElementById('searchResults').innerHTML =
                            '<tr><td colspan="7" class="fc-empty-cell">Enter a term and click Search.</td></tr>';
                        document.getElementById('idFeedback').style.display = 'none';

                        // Set the ID and trigger a full lookup for normalized class/section
                        document.getElementById('user_id').value = s.user_id || '';
                        lookupStudentById();
                        showToast('Looking up student: ' + s.name, 'info');
                    });
                    tbody.appendChild(tr);
                });
            })
            .catch(function() {
                tbody.innerHTML =
                    '<tr><td colspan="7" class="fc-empty-cell" style="color:var(--fc-red);">Search failed.</td></tr>';
            });
    }

    /* ── Submit Fees ── */
    function submitFees() {
        // Validate first
        if (!FC.userId) { showAlert('Please select a student.', 'error'); return; }
        if (!FC.selectedMonths.length) { showAlert('Please select at least one month.', 'error'); return; }
        var paymentMode = document.getElementById('accountSelect').value;
        if (!paymentMode) { showAlert('Please select a payment mode.', 'error'); return; }
        var schoolFees = parseFloat(document.getElementById('submitSchoolFees').value) || 0;
        if (schoolFees <= 0) { showAlert('Please enter the fee amount.', 'error'); return; }

        // Show confirmation modal instead of submitting directly
        var fineAmt = parseFloat(document.getElementById('fineAmount').value) || 0;
        var due = Math.max(0, FC.grandTotal - FC.discountAmt - FC.overpaidAmt);
        var acctText = document.getElementById('accountSelect').options[document.getElementById('accountSelect').selectedIndex].text;

        var ch = '<div style="font-size:13px;line-height:1.7;color:var(--fc-navy,#1a2940)">';
        ch += '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--fc-border)"><span>Student</span><strong>' + (FC.studentName || '—') + '</strong></div>';
        ch += '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--fc-border)"><span>Class</span><strong>' + getDisplayClassSection() + '</strong></div>';
        ch += '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--fc-border)"><span>Months</span><strong>' + FC.selectedMonths.join(', ') + '</strong></div>';
        ch += '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--fc-border)"><span>Payment Mode</span><strong>' + acctText + '</strong></div>';
        ch += '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--fc-border)"><span>Total Fee</span><strong>₹ ' + fmtNum(FC.grandTotal) + '</strong></div>';
        if (FC.discountAmt > 0) ch += '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--fc-border);color:var(--fc-green)"><span>Discount</span><strong>- ₹ ' + fmtNum(FC.discountAmt) + '</strong></div>';
        if (FC.overpaidAmt > 0) ch += '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--fc-border);color:var(--fc-green)"><span>Advance Applied</span><strong>- ₹ ' + fmtNum(FC.overpaidAmt) + '</strong></div>';
        if (fineAmt > 0) ch += '<div style="display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--fc-border);color:var(--fc-red)"><span>Fine</span><strong>+ ₹ ' + fmtNum(fineAmt) + '</strong></div>';
        ch += '<div style="display:flex;justify-content:space-between;padding:10px 0;font-size:16px"><span style="font-weight:700">Amount to Collect</span><strong style="color:var(--fc-teal);font-size:18px">₹ ' + fmtNum(schoolFees + fineAmt) + '</strong></div>';
        ch += '</div>';

        document.getElementById('confirmBody').innerHTML = ch;
        openModal('confirmModal');

        // Bind the confirm button
        document.getElementById('confirmSubmitBtn').onclick = function() {
            closeModal('confirmModal');
            doActualSubmit(paymentMode, schoolFees, fineAmt);
        };
    }

    function doActualSubmit(paymentMode, schoolFees, fineAmt) {
        var reference = document.getElementById('reference').value.trim() || 'Fees Submitted';

        var btn = document.getElementById('submitFeesBtn');
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting…';

        /* Build FormData manually so we can append indexed monthTotals */
        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH); /* ← layer 1: CI built-in filter */
        fd.append('receiptNo', RECEIPT_NO);
        fd.append('paymentMode', paymentMode);
        fd.append('class', FC.className); // e.g. "Class 8th"
        fd.append('section', FC.sectionName); // e.g. "Section B"
        fd.append('userId', FC.userId);
        fd.append('submitAmount', FC.overpaidAmt.toFixed(2));
        fd.append('schoolFees', schoolFees.toFixed(2));
        fd.append('discountAmount', FC.discountAmt.toFixed(2));
        fd.append('fineAmount', fineAmt.toFixed(2));
        fd.append('reference', reference);

        FC.selectedMonths.forEach(function(m) {
            fd.append('selectedMonths[]', m);
        });
        FC.selectedMonths.forEach(function(m, i) {
            fd.append('monthTotals[' + i + '][month]', m);
            fd.append('monthTotals[' + i + '][total]', FC.monthFeeMap[m] || 0);
        });

        fetch(SITE_URL + '/fees/submit_fees', {
                method: 'POST',
                body: fd,
                headers: {
                    'X-CSRF-Token': CSRF_HASH,
                    /* ← layer 2: MY_Controller */
                    'X-Requested-With': 'XMLHttpRequest'
                }
            })
            .then(function(r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function(resp) {
                if (resp.status === 'success') {
                    showToast('Fees submitted successfully!', 'success');

                    // Show success modal with receipt preview
                    var rn = resp.receipt_no || RECEIPT_NO;
                    document.getElementById('successMsg').textContent = 'Receipt #' + rn + ' — ₹ ' + fmtNum(parseFloat(document.getElementById('submitSchoolFees').value)||0) + ' collected from ' + FC.studentName;
                    document.getElementById('successDetail').innerHTML =
                        '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span>Student</span><strong>' + FC.studentName + '</strong></div>'
                        + '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span>Months</span><strong>' + FC.selectedMonths.join(', ') + '</strong></div>'
                        + '<div style="display:flex;justify-content:space-between"><span>Receipt No.</span><strong>#' + rn + '</strong></div>';
                    document.getElementById('successPrintBtn').href = SITE_URL + '/fees/print_receipt/' + rn;
                    openModal('successModal');

                    document.getElementById('breakdownCard').style.display = 'none';
                    document.getElementById('paymentCard').style.display = 'none';
                    document.getElementById('allocPreview').style.display = 'none';
                    document.getElementById('quickPayBtns').style.display = 'none';
                    document.querySelectorAll('.fc-month-tile').forEach(function(t) {
                        t.classList.remove('selected');
                    });
                    FC.selectedMonths = [];
                    FC.monthFeeMap = {};
                    FC.grandTotal = FC.discountAmt = FC.overpaidAmt = 0;
                    recalc();

                    /* Refresh receipt number — GET, no CSRF needed */
                    fetch(SITE_URL + '/fees/get_receipt_no')
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(d) {
                            if (d.receiptNo) {
                                RECEIPT_NO = d.receiptNo;
                                document.getElementById('receiptNo').value = d.receiptNo;
                                document.getElementById('topReceiptNo').textContent = d.receiptNo;
                                var s = document.getElementById('sumReceiptNo');
                                if (s) s.textContent = d.receiptNo;
                            }
                        }).catch(function() {});

                    var acct = document.getElementById('accountSelect');
                    if (acct) acct.value = '';
                    var spm = document.getElementById('sumPaymentMode');
                    if (spm) spm.textContent = '—';

                    fetchMonths(FC.userId);
                    setTimeout(function() {
                        loadHistory();
                    }, 800);

                } else {
                    showAlert('Error: ' + (resp.message || 'Unknown error'), 'error');
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Fees';
                }
            })
            .catch(function(e) {
                console.error('submitFees:', e);
                showAlert('Network error during submission.', 'error');
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Fees';
            });
    }

    /* ── Payment History ── */
    function loadHistory() {
        if (!FC.userId) {
            showToast('Select a student first.', 'warning');
            return;
        }

        var tbody = document.getElementById('historyTbody');
        tbody.innerHTML =
            '<tr><td colspan="6" class="fc-empty-cell"><i class="fa fa-spinner fa-spin"></i> Loading…</td></tr>';
        openModal('historyModal');

        postForm(SITE_URL + '/fees/fetch_fee_receipts', {
                userId: FC.userId
            })
            .then(function(data) {
                tbody.innerHTML = '';
                if (!data || !data.length) {
                    tbody.innerHTML = '<tr><td colspan="6" class="fc-empty-cell">No payment records found.</td></tr>';
                    return;
                }
                var tAmt = 0,
                    tFin = 0,
                    tDis = 0;
                data.forEach(function(rec) {
                    var amt = parseFloat(String(rec.amount || 0).replace(/,/g, ''));
                    var fin = parseFloat(String(rec.fine || 0).replace(/,/g, ''));
                    var dis = parseFloat(String(rec.discount || 0).replace(/,/g, ''));
                    tAmt += amt;
                    tFin += fin;
                    tDis += dis;
                    var tr = document.createElement('tr');
                    tr.innerHTML =
                        '<td><span class="fc-receipt-pill">F' + rec.receiptNo + '</span></td>' +
                        '<td>' + (rec.date || '') + '</td>' +
                        '<td style="text-align:right;font-weight:600;">₹ ' + fmtNum(amt) + '</td>' +
                        '<td style="text-align:right;">₹ ' + fmtNum(fin) + '</td>' +
                        '<td style="text-align:right;color:var(--fc-green);">₹ ' + fmtNum(dis) + '</td>' +
                        '<td><span class="fc-mode-pill">' + (rec.account || 'N/A') + '</span></td>';
                    tbody.appendChild(tr);
                });
                var tot = document.createElement('tr');
                tot.className = 'fc-history-total';
                tot.innerHTML =
                    '<td colspan="2"><strong>TOTAL</strong></td>' +
                    '<td style="text-align:right;"><strong>₹ ' + fmtNum(tAmt) + '</strong></td>' +
                    '<td style="text-align:right;"><strong>₹ ' + fmtNum(tFin) + '</strong></td>' +
                    '<td style="text-align:right;"><strong>₹ ' + fmtNum(tDis) + '</strong></td>' +
                    '<td></td>';
                tbody.appendChild(tot);
                FC.alreadyPaid = tAmt;
                recalc();
            })
            .catch(function(e) {
                console.error('loadHistory:', e);
                tbody.innerHTML =
                    '<tr><td colspan="6" class="fc-empty-cell" style="color:var(--fc-red);">Failed to load history.</td></tr>';
            });
    }

    /* ── DOMContentLoaded ── */
    document.addEventListener('DOMContentLoaded', function() {

        /* Auto-load from ?uid= */
        var urlUid = new URLSearchParams(window.location.search).get('uid');
        if (urlUid) {
            var uidEl = document.getElementById('user_id');
            var spEl = document.getElementById('idSpinner');
            var fbEl = document.getElementById('idFeedback');
            var fnEl = document.getElementById('findBtn');

            uidEl.value = urlUid;
            spEl.style.display = 'inline';
            fnEl.disabled = true;
            fbEl.className = 'fc-id-feedback fc-id-feedback-info';
            fbEl.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Auto-loading <strong>' + urlUid + '</strong>…';
            fbEl.style.display = 'block';

            postForm(SITE_URL + '/fees/lookup_student', {
                    user_id: urlUid
                })
                .then(function(data) {
                    spEl.style.display = 'none';
                    fnEl.disabled = false;
                    if (data.error) {
                        fbEl.className = 'fc-id-feedback fc-id-feedback-error';
                        fbEl.textContent = '✗ ' + data.error;
                        return;
                    }
                    selectStudent(data);
                    fbEl.className = 'fc-id-feedback fc-id-feedback-success';
                    fbEl.textContent = '✓ Auto-loaded: ' + data.name;
                    showToast('Loaded: ' + data.name, 'success');
                })
                .catch(function(e) {
                    spEl.style.display = 'none';
                    fnEl.disabled = false;
                    fbEl.className = 'fc-id-feedback fc-id-feedback-error';
                    fbEl.textContent = '✗ Network error. Enter ID manually.';
                    console.error('uid auto-load:', e);
                });
        }

        /* Server date — GET, no CSRF */
        fetch(SITE_URL + '/fees/get_server_date')
            .then(function(r) {
                return r.json();
            })
            .then(function(d) {
                if (d.date) {
                    document.getElementById('fcDate').value = d.date;
                    document.getElementById('sumDate').textContent = d.date;
                }
            }).catch(function() {});

        document.getElementById('findBtn').addEventListener('click', lookupStudentById);
        document.getElementById('user_id').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                lookupStudentById();
            }
        });
        document.getElementById('openSearchBtn').addEventListener('click', function() {
            openModal('searchModal');
        });
        document.getElementById('doSearchBtn').addEventListener('click', doSearch);
        document.getElementById('searchInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') doSearch();
        });

        document.getElementById('fetchDetailsBtn').addEventListener('click', fetchFeeDetails);
        document.getElementById('selectAllBtn').addEventListener('click', function() {
            document.querySelectorAll('.fc-month-tile:not(.paid)').forEach(function(t) {
                t.classList.add('selected');
            });
            updateFromTiles();
        });
        document.getElementById('clearAllBtn').addEventListener('click', function() {
            document.querySelectorAll('.fc-month-tile').forEach(function(t) {
                t.classList.remove('selected');
            });
            updateFromTiles();
        });

        document.getElementById('expandBreakdownBtn').addEventListener('click', function() {
            openModal('expandModal');
        });
        document.getElementById('feesRecordBtn').addEventListener('click', loadHistory);
        document.getElementById('submitFeesBtn').addEventListener('click', submitFees);

        ['submitSchoolFees', 'fineAmount'].forEach(function(id) {
            var el = document.getElementById(id);
            if (el) el.addEventListener('input', function() { recalc(); updateAllocationPreview(); });
        });

        var acctEl = document.getElementById('accountSelect');
        if (acctEl) {
            acctEl.addEventListener('change', function() {
                var s = document.getElementById('sumPaymentMode');
                if (s) s.textContent = acctEl.options[acctEl.selectedIndex].text || '—';
            });
        }

        recalc();
    });
</script>

<!-- ══ STYLES ══ -->
<style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap');

    :root {
        /* Map fee-counter vars to global theme vars so dark/light toggle works */
        --fc-navy: var(--t1);
        /* headings / dark text            */
        --fc-teal: var(--gold);
        /* primary accent (ERP gold)        */
        --fc-sky: var(--gold-dim);
        /* light accent background          */
        --fc-green: #16a34a;
        /* paid / success  (semantic)       */
        --fc-red: #dc2626;
        /* due / error     (semantic)       */
        --fc-amber: var(--gold);
        /* warning accent                   */
        --fc-blue: #2563eb;
        /* info            (semantic)       */
        --fc-text: var(--t2);
        /* body text                        */
        --fc-muted: var(--t3);
        /* label / muted text               */
        --fc-border: var(--border);
        /* borders                          */
        --fc-white: var(--bg2);
        /* card backgrounds                 */
        --fc-bg: var(--bg);
        /* page background                  */
        --fc-shadow: var(--sh);
        /* box shadows                      */
        --fc-radius: 12px;
    }

    * {
        box-sizing: border-box;
    }

    /* Shell */
    .fc-wrap {
        font-family: 'DM Sans', sans-serif;
        background: var(--fc-bg);
        color: var(--fc-text);
        padding: 24px 20px 60px;
        min-height: 100vh;
    }

    /* Top bar */
    .fc-topbar {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 22px;
    }

    .fc-page-title {
        font-family: 'Playfair Display', serif;
        font-size: 24px;
        font-weight: 700;
        color: var(--fc-navy);
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 5px;
    }

    .fc-page-title i {
        color: var(--fc-teal);
    }

    .fc-breadcrumb {
        display: flex;
        gap: 6px;
        list-style: none;
        margin: 0;
        padding: 0;
        font-size: 12.5px;
        color: var(--fc-muted);
    }

    .fc-breadcrumb a {
        color: var(--fc-teal);
        text-decoration: none;
        font-weight: 500;
    }

    .fc-breadcrumb li::before {
        content: '/';
        margin-right: 6px;
        color: var(--border);
    }

    .fc-breadcrumb li:first-child::before {
        display: none;
    }

    .fc-receipt-badge {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 2px;
    }

    .fc-receipt-label {
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .6px;
        text-transform: uppercase;
        color: var(--fc-muted);
    }

    .fc-receipt-num {
        font-family: 'Playfair Display', serif;
        font-size: 26px;
        font-weight: 700;
        color: var(--gold);
        line-height: 1;
    }

    /* Stat strip */
    .fc-stat-strip {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
        margin-bottom: 20px;
    }

    @media(max-width:768px) {
        .fc-stat-strip {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    .fc-stat {
        background: var(--fc-white);
        border-radius: var(--fc-radius);
        box-shadow: var(--fc-shadow);
        border: 1px solid var(--fc-border);
        padding: 14px 16px;
        display: flex;
        align-items: center;
        gap: 12px;
        transition: transform .15s;
    }

    .fc-stat:hover {
        transform: translateY(-2px);
    }

    .fc-stat-icon {
        width: 42px;
        height: 42px;
        border-radius: 10px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 17px;
        flex-shrink: 0;
    }

    .fc-stat-blue .fc-stat-icon {
        background: #dbeafe;
        color: var(--fc-blue);
    }

    .fc-stat-green .fc-stat-icon {
        background: #dcfce7;
        color: var(--fc-green);
    }

    .fc-stat-amber .fc-stat-icon {
        background: #fef3c7;
        color: var(--fc-amber);
    }

    .fc-stat-red .fc-stat-icon {
        background: #fee2e2;
        color: var(--fc-red);
    }

    .fc-stat-label {
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
        color: var(--fc-muted);
        margin-bottom: 3px;
    }

    .fc-stat-val {
        font-family: 'Playfair Display', serif;
        font-size: 18px;
        font-weight: 700;
        color: var(--fc-navy);
    }

    /* Layout */
    .fc-layout {
        display: grid;
        grid-template-columns: 1fr 300px;
        gap: 18px;
        align-items: start;
    }

    @media(max-width:960px) {
        .fc-layout {
            grid-template-columns: 1fr;
        }

        .fc-right {
            order: -1;
        }
    }

    /* Card */
    .fc-card {
        background: var(--fc-white);
        border-radius: var(--fc-radius);
        box-shadow: var(--fc-shadow);
        border: 1px solid var(--fc-border);
        margin-bottom: 16px;
        overflow: hidden;
    }

    .fc-card-head {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 13px 18px;
        border-bottom: 1px solid var(--fc-border);
        background: linear-gradient(90deg, var(--gold-dim) 0%, var(--bg2) 100%);
    }

    .fc-step {
        width: 24px;
        height: 24px;
        border-radius: 50%;
        background: var(--fc-teal);
        color: #fff;
        font-size: 12px;
        font-weight: 700;
        display: flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
    }

    .fc-card-head i {
        color: var(--fc-teal);
        flex-shrink: 0;
    }

    .fc-card-head h3 {
        font-family: 'Playfair Display', serif;
        font-size: 14.5px;
        font-weight: 700;
        color: var(--fc-navy);
        margin: 0;
    }

    .fc-head-hint {
        font-size: 12.5px;
        color: var(--fc-muted);
        font-weight: 400;
        margin-left: 4px;
    }

    .fc-card-body {
        padding: 18px;
    }

    /* Grid */
    .fc-grid-2 {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 12px;
    }

    .fc-grid-3 {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: 12px;
    }

    .fc-mb {
        margin-bottom: 12px;
    }

    @media(max-width:600px) {

        .fc-grid-2,
        .fc-grid-3 {
            grid-template-columns: 1fr;
        }
    }

    /* Fields */
    .fc-field {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .fc-label {
        font-size: 12.5px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
        color: var(--fc-muted);
    }

    .fc-req {
        color: var(--fc-red);
    }

    .fc-input,
    .fc-select {
        height: 38px;
        padding: 0 10px;
        border: 1.5px solid var(--fc-border);
        border-radius: 8px;
        font-size: 13.5px;
        color: var(--fc-text);
        background: var(--bg3, #fafcff);
        font-family: 'DM Sans', sans-serif;
        outline: none;
        width: 100%;
        transition: border-color .13s, box-shadow .13s;
    }

    .fc-input:focus,
    .fc-select:focus {
        border-color: var(--fc-teal);
        box-shadow: 0 0 0 3px rgba(245, 175, 0, .15);
        background: var(--bg2, #fff);
    }

    .fc-input[readonly] {
        background: var(--bg3, #f1f5f9);
        color: var(--fc-muted);
        cursor: default;
    }

    .fc-input-green {
        color: var(--fc-green) !important;
        font-weight: 600;
    }

    .fc-input-primary {
        border-color: var(--fc-teal);
        font-weight: 600;
    }

    .fc-select-wrap {
        position: relative;
    }

    .fc-select {
        appearance: none;
        padding-right: 28px;
        cursor: pointer;
    }

    .fc-select-arrow {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--fc-muted);
        font-size: 12px;
        pointer-events: none;
    }

    /* Student row — Find + Browse side by side */
    .fc-student-row {
        display: flex;
        gap: 8px;
        align-items: center;
    }

    .fc-id-wrap {
        position: relative;
        flex: 1;
    }

    .fc-id-wrap .fc-input {
        width: 100%;
    }

    .fc-id-spinner {
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--fc-teal);
        font-size: 14px;
    }

    .fc-id-feedback {
        font-size: 12.5px;
        font-weight: 500;
        margin-top: 5px;
        padding: 4px 8px;
        border-radius: 6px;
    }

    .fc-id-feedback-success {
        color: var(--fc-green);
        background: #f0fdf4;
        border: 1px solid #86efac;
    }

    .fc-id-feedback-error {
        color: var(--fc-red);
        background: #fef2f2;
        border: 1px solid #fca5a5;
    }

    .fc-id-feedback-info {
        color: var(--gold);
        background: var(--gold-dim);
        border: 1px solid rgba(245, 175, 0, .4);
    }

    /* Month tiles */
    .fc-month-grid {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
        gap: 8px;
        margin-bottom: 14px;
        min-height: 60px;
    }

    .fc-month-tile {
        border: 2px solid var(--fc-border);
        border-radius: 10px;
        padding: 10px 8px;
        text-align: center;
        cursor: pointer;
        position: relative;
        transition: border-color .15s, background .15s, transform .1s;
        background: var(--fc-white);
        user-select: none;
    }

    .fc-month-tile:hover:not(.paid) {
        border-color: var(--fc-teal);
        background: var(--fc-sky);
        transform: translateY(-1px);
    }

    .fc-month-tile.selected {
        border-color: var(--fc-teal);
        background: var(--fc-sky);
    }

    .fc-month-tile.selected .fc-month-name {
        color: var(--fc-teal);
        font-weight: 700;
    }

    .fc-month-tile.paid {
        background: #f0fdf4;
        border-color: #86efac;
        cursor: not-allowed;
        opacity: .75;
    }

    .fc-month-name {
        font-size: 12px;
        font-weight: 600;
        color: var(--fc-navy);
        margin-bottom: 4px;
    }

    .fc-month-status {
        font-size: 12px;
        color: var(--fc-muted);
    }

    .fc-month-tile.paid .fc-month-status {
        color: var(--fc-green);
    }

    .fc-months-placeholder {
        text-align: center;
        color: var(--fc-muted);
        padding: 30px 20px;
        grid-column: 1/-1;
    }

    .fc-months-placeholder i {
        font-size: 28px;
        opacity: .35;
        display: block;
        margin-bottom: 8px;
    }

    .fc-months-placeholder p {
        margin: 0;
        font-size: 13.5px;
    }

    .fc-month-actions {
        display: flex;
        gap: 10px;
        align-items: center;
        flex-wrap: wrap;
        padding-top: 10px;
        border-top: 1px solid var(--fc-border);
    }

    /* Due bar */
    .fc-due-bar {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: 8px;
        background: var(--fc-bg);
        border: 1px solid var(--fc-border);
        border-radius: 10px;
        padding: 14px 18px;
        margin-bottom: 18px;
    }

    .fc-due-item {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .fc-due-item-big .fc-due-label {
        color: var(--fc-red);
    }

    .fc-due-sep {
        font-size: 18px;
        font-weight: 700;
        color: var(--fc-muted);
        padding: 0 4px;
    }

    .fc-due-label {
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
        color: var(--fc-muted);
    }

    .fc-due-val {
        font-family: 'Playfair Display', serif;
        font-size: 17px;
        font-weight: 700;
        color: var(--fc-navy);
    }

    .fc-due-val.fc-green {
        color: var(--fc-green);
    }

    .fc-due-val.fc-red {
        color: var(--fc-red);
        font-size: 20px;
    }

    /* Table */
    .fc-table-wrap {
        overflow-x: auto;
    }

    .fc-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13.5px;
    }

    .fc-table thead tr {
        background: linear-gradient(135deg, var(--gold) 0%, #D49800 100%);
    }

    .fc-table thead th {
        padding: 9px 12px;
        font-size: 12.5px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
        color: #fff;
        white-space: nowrap;
    }

    .fc-table td {
        padding: 9px 12px;
        border-bottom: 1px solid var(--fc-border);
        vertical-align: middle;
    }

    .fc-table tbody tr:hover {
        background: var(--fc-sky);
    }

    .fc-table tfoot td {
        background: var(--gold-dim);
        font-weight: 700;
        border-top: 2px solid var(--gold);
        padding: 10px 12px;
        color: var(--t1);
    }

    .fc-empty-cell {
        text-align: center;
        color: var(--fc-muted);
        padding: 28px !important;
    }

    .fc-history-total td {
        background: #f0fdf4;
        border-top: 2px solid var(--fc-green);
    }

    /* Pills */
    .fc-receipt-pill {
        background: var(--gold-dim);
        color: var(--gold);
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
    }

    .fc-mode-pill {
        background: var(--bg3, #f1f5f9);
        color: var(--fc-muted);
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 12px;
    }

    .fc-id-pill {
        background: #dbeafe;
        color: var(--fc-blue);
        padding: 2px 8px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 700;
    }

    /* Action bar */
    .fc-action-bar {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        flex-wrap: wrap;
    }

    /* Quick pay buttons */
    .fc-quick-pay {
        display: flex;
        gap: 8px;
        margin-bottom: 14px;
    }

    /* Allocation preview */
    .fc-alloc-preview {
        background: var(--fc-sky);
        border: 1px solid var(--fc-border);
        border-radius: 10px;
        padding: 14px 16px;
        margin-bottom: 14px;
    }
    .fc-alloc-title {
        font-size: 12.5px;
        font-weight: 700;
        color: var(--fc-teal);
        margin-bottom: 8px;
    }
    .fc-alloc-list { display: flex; flex-direction: column; gap: 6px; }
    .fc-alloc-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 7px 10px;
        border-radius: 6px;
        background: var(--fc-white);
        border: 1px solid var(--fc-border);
        font-size: 13px;
    }
    .fc-alloc-item.cleared { border-left: 3px solid var(--fc-teal); }
    .fc-alloc-item.partial { border-left: 3px solid #d97706; }
    .fc-alloc-item .alloc-month { font-weight: 600; color: var(--fc-navy); }
    .fc-alloc-item .alloc-amount { font-weight: 700; font-family: var(--font-m); }
    .fc-alloc-item .alloc-tag {
        font-size: 10px; font-weight: 700; padding: 2px 7px; border-radius: 4px;
        text-transform: uppercase; letter-spacing: .5px;
    }
    .fc-alloc-item .alloc-tag.cleared-tag { background: rgba(15,118,110,.1); color: var(--fc-teal); }
    .fc-alloc-item .alloc-tag.partial-tag { background: rgba(217,119,6,.1); color: #d97706; }
    .fc-alloc-advance {
        margin-top: 8px; padding: 8px 12px; border-radius: 6px;
        background: rgba(15,118,110,.08); border: 1px solid rgba(15,118,110,.2);
        font-size: 12.5px; color: var(--fc-teal); font-weight: 600;
    }

    /* Month tile overdue highlight */
    .fc-month-tile.overdue {
        border-color: #ef4444 !important;
        background: rgba(239,68,68,.06) !important;
    }
    .fc-month-tile.overdue .fc-month-name { color: #dc2626 !important; }

    /* Buttons */
    .fc-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 9px 18px;
        border-radius: 8px;
        border: none;
        font-family: 'DM Sans', sans-serif;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        white-space: nowrap;
        transition: opacity .13s, transform .1s;
    }

    .fc-btn:hover:not(:disabled) {
        opacity: .85;
        transform: translateY(-1px);
    }

    .fc-btn:disabled {
        opacity: .45;
        cursor: not-allowed;
        transform: none;
    }

    .fc-btn-sm {
        padding: 7px 14px;
        font-size: 12.5px;
    }

    .fc-btn-xs {
        padding: 5px 10px;
        font-size: 12px;
    }

    .fc-btn-primary {
        background: linear-gradient(135deg, var(--gold) 0%, #D49800 100%);
        color: #fff;
        box-shadow: 0 2px 8px rgba(245, 175, 0, .3);
    }

    .fc-btn-amber {
        background: var(--fc-amber);
        color: #fff;
    }

    .fc-btn-ghost {
        background: var(--fc-white);
        color: var(--fc-text);
        border: 1.5px solid var(--fc-border);
    }

    .fc-btn-ghost:hover {
        border-color: var(--fc-teal);
        color: var(--fc-teal);
        opacity: 1;
    }

    .fc-btn-info {
        background: var(--fc-blue);
        color: #fff;
    }

    .fc-btn-submit {
        background: linear-gradient(135deg, var(--gold) 0%, #D49800 100%);
        color: #fff;
        box-shadow: 0 2px 10px rgba(245, 175, 0, .35);
        font-size: 14px;
        padding: 10px 24px;
    }

    .fc-btn-submit:hover {
        opacity: .88;
    }

    /* Alert */
    .fc-alert {
        padding: 12px 16px;
        border-radius: 8px;
        margin-bottom: 14px;
        font-size: 13.5px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .fc-alert-danger {
        background: #fee2e2;
        color: var(--fc-red);
        border: 1px solid #fca5a5;
    }

    .fc-alert-warning {
        background: #fef3c7;
        color: var(--fc-amber);
        border: 1px solid #fde68a;
    }

    .fc-alert-info {
        background: #e0f2fe;
        color: var(--fc-teal);
        border: 1px solid #bae6fd;
    }

    .fc-alert-success {
        background: #f0fdf4;
        color: var(--fc-green);
        border: 1px solid #86efac;
    }

    /* Summary card (right) */
    .fc-right {
        position: sticky;
        top: 16px;
    }

    .fc-summary-card {
        background: #0b1f3a;
        border-radius: var(--fc-radius);
        overflow: hidden;
        box-shadow: 0 4px 24px rgba(0, 0, 0, .25);
    }

    .fc-summary-head {
        padding: 14px 18px;
        color: #fff;
        font-size: 12px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
        border-bottom: 1px solid rgba(255, 255, 255, .1);
        display: flex;
        align-items: center;
        gap: 8px;
        background: linear-gradient(135deg, var(--gold) 0%, #D49800 100%);
    }

    .fc-summary-body {
        padding: 16px 18px;
    }

    .fc-summary-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 7px 0;
        border-bottom: 1px solid rgba(255, 255, 255, .07);
        font-size: 13px;
        color: rgba(255, 255, 255, .65);
    }

    .fc-summary-row strong {
        color: rgba(255, 255, 255, .9);
        font-size: 13.5px;
    }

    .fc-summary-row.fc-green strong {
        color: #4ade80;
    }

    .fc-summary-divider {
        border-bottom: 1px solid rgba(255, 255, 255, .15);
        margin: 6px 0;
    }

    .fc-summary-due {
        color: rgba(255, 255, 255, .8);
        background: rgba(220, 38, 38, .18);
        margin: 0 -18px;
        padding: 10px 18px !important;
        border: none !important;
    }

    .fc-summary-due strong {
        color: #f87171 !important;
        font-size: 17px !important;
    }

    .fc-summary-payable {
        background: rgba(245, 175, 0, .2);
        margin: 0 -18px;
        padding: 10px 18px !important;
        border: none !important;
    }

    .fc-summary-payable strong {
        color: #fbbf24 !important;
        font-size: 16px !important;
    }

    /* Modal */
    .fc-overlay {
        position: fixed;
        inset: 0;
        background: rgba(11, 31, 58, .55);
        z-index: 9000;
        display: none;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }

    .fc-overlay.open {
        display: flex;
    }

    .fc-modal {
        background: var(--fc-white);
        border-radius: var(--fc-radius);
        box-shadow: 0 20px 60px rgba(0, 0, 0, .25);
        width: 100%;
        max-width: 620px;
        max-height: 88vh;
        overflow-y: auto;
        animation: fc-modal-in .2s ease;
    }

    .fc-modal-wide {
        max-width: 980px;
    }

    .fc-modal-head {
        background: linear-gradient(135deg, var(--gold) 0%, #D49800 100%);
        color: #fff;
        padding: 14px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: sticky;
        top: 0;
        z-index: 2;
        border-radius: var(--fc-radius) var(--fc-radius) 0 0;
    }

    .fc-modal-head h4 {
        font-family: 'Playfair Display', serif;
        font-size: 16px;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .fc-modal-close {
        background: none;
        border: none;
        color: rgba(255, 255, 255, .65);
        font-size: 22px;
        cursor: pointer;
        transition: color .13s;
        line-height: 1;
    }

    .fc-modal-close:hover {
        color: #fff;
    }

    .fc-modal-body {
        padding: 20px;
    }

    @keyframes fc-modal-in {
        from {
            transform: translateY(16px);
            opacity: 0
        }

        to {
            transform: translateY(0);
            opacity: 1
        }
    }

    .fc-search-box {
        display: flex;
        gap: 10px;
        margin-bottom: 14px;
    }

    .fc-search-box .fc-input {
        flex: 1;
    }

    /* Toast */
    .fc-toast-wrap {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 99999;
        display: flex;
        flex-direction: column;
        gap: 8px;
        pointer-events: none;
    }

    .fc-toast {
        padding: 12px 18px;
        border-radius: 10px;
        color: #fff;
        font-size: 13.5px;
        font-weight: 600;
        box-shadow: 0 4px 18px rgba(0, 0, 0, .2);
        display: flex;
        align-items: center;
        gap: 9px;
        animation: fc-toast-in .22s ease;
        max-width: 320px;
        pointer-events: auto;
        transition: opacity .3s;
    }

    .fc-toast-hide {
        opacity: 0;
    }

    .fc-toast-success {
        background: var(--fc-green);
    }

    .fc-toast-error {
        background: var(--fc-red);
    }

    .fc-toast-warning {
        background: var(--fc-amber);
    }

    .fc-toast-info {
        background: linear-gradient(135deg, var(--gold) 0%, #D49800 100%);
    }

    @keyframes fc-toast-in {
        from {
            transform: translateX(20px);
            opacity: 0
        }

        to {
            transform: translateX(0);
            opacity: 1
        }
    }

    /* Summary panel — Payment History button */
    .fc-summary-history {
        padding: 14px 18px;
        border-top: 1px solid rgba(255, 255, 255, .1);
    }

    .fc-btn-history-full {
        width: 100%;
        justify-content: center;
        background: rgba(245, 175, 0, .2);
        color: #fbbf24;
        border: 1px solid rgba(245, 175, 0, .4);
        border-radius: 8px;
        padding: 10px 16px;
        font-size: 13px;
        font-weight: 600;
        transition: background .15s;
    }

    .fc-btn-history-full:hover {
        background: rgba(245, 175, 0, .38);
        color: #fff;
        opacity: 1;
    }

    /* Utilities */
    .fc-green {
        color: var(--fc-green);
    }

    .fc-red {
        color: var(--fc-red);
    }
</style>