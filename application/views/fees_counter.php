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
                                <button type="button" class="fc-btn fc-btn-amber" id="findBtn"
                                        onclick="if(typeof lookupStudentById==='function') lookupStudentById();">
                                    <i class="fa fa-search"></i> Find
                                </button>
                                <button type="button" class="fc-btn fc-btn-ghost" id="openSearchBtn"
                                        onclick="if(typeof openModal==='function'){openModal('searchModal');if(typeof doSearch==='function')doSearch();}">
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

                        <div class="fc-grid-2">
                            <div class="fc-field">
                                <label class="fc-label">Class</label>
                                <input type="text" id="fcClass" class="fc-input" placeholder="Auto-filled" readonly>
                            </div>
                            <div class="fc-field">
                                <label class="fc-label">Section</label>
                                <input type="text" id="fcSection" class="fc-input" placeholder="Auto-filled" readonly>
                            </div>
                        </div>

                        <!-- Discount / Scholarship banner — appears when the
                             student has a discount on file (or to invite a
                             cashier to grant one). Inline grant via the
                             button on the right. -->
                        <div id="discountBanner" class="fc-disc-banner" style="display:none;">
                            <div class="fc-disc-icon"><i class="fa fa-gift"></i></div>
                            <div class="fc-disc-body">
                                <div class="fc-disc-title">
                                    Discount on file:
                                    <strong id="discountBannerAmt">₹ 0.00</strong>
                                    <span id="discountBannerExpiry" class="fc-disc-expiry" style="display:none;"></span>
                                </div>
                                <div class="fc-disc-sub">
                                    Auto-applied to every fee fetch. Manage in
                                    <a href="<?= site_url('fee_management/discounts') ?>" class="fc-disc-link">
                                        Discount Policies
                                    </a>
                                    or
                                    <a href="<?= site_url('fee_management/scholarships') ?>" class="fc-disc-link">
                                        Scholarships
                                    </a>.
                                </div>
                            </div>
                            <button type="button" class="fc-disc-edit-btn"
                                    onclick="openGrantDiscountModal()" title="Edit / clear">
                                <i class="fa fa-pencil"></i> Edit
                            </button>
                        </div>

                        <!-- Always-visible "Grant Discount" affordance — shown
                             after a student is loaded but only when no
                             discount is on file (banner state). -->
                        <div id="grantDiscountInvite" class="fc-grant-invite" style="display:none;">
                            <span class="fc-grant-icon"><i class="fa fa-gift"></i></span>
                            <span>No discount on file for this student.</span>
                            <button type="button" class="fc-grant-link" onclick="openGrantDiscountModal()">
                                <i class="fa fa-plus-circle"></i> Grant a one-off discount
                            </button>
                        </div>

                        <!-- Hidden: kept for legacy JS that still writes to it.
                             The visible value is now the banner above. -->
                        <input type="hidden" id="discountDisplay" value="₹ 0.00">

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
                <!-- ══ STEP 4: Submit Payment (redesigned) ══ -->
                <div class="fc-card pay-card" id="paymentCard" style="display:none;">
                    <div class="pay-head">
                        <div class="pay-head-left">
                            <span class="pay-step">4</span>
                            <div>
                                <h3>Submit Payment</h3>
                                <p class="pay-head-sub">Choose mode, enter amount and review allocation before submitting.</p>
                            </div>
                        </div>
                        <div class="pay-head-due" id="payHeadDue" style="display:none;">
                            <span class="pay-head-due-label">Net payable</span>
                            <span class="pay-head-due-val" id="payHeadDueVal">₹ 0.00</span>
                        </div>
                    </div>

                    <div class="pay-body">

                        <!-- Row 1 — Payment Mode (full width, primary control) -->
                        <div class="pay-section">
                            <div class="pay-section-head">
                                <i class="fa fa-credit-card"></i>
                                <span>Mode of Payment</span>
                                <span class="fc-req">*</span>
                            </div>
                            <div class="fc-select-wrap pay-mode-wrap">
                                <select id="accountSelect" class="fc-select pay-mode-select" required>
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

                        <!-- Row 2 — Quick-amount controls + amount inputs -->
                        <div class="pay-section">
                            <div class="pay-section-head">
                                <i class="fa fa-rupee-sign fa-money-bill"></i>
                                <span>Amount</span>
                            </div>
                            <div class="pay-quick-row" id="quickPayBtns" style="display:none;">
                                <button type="button" class="pay-chip pay-chip-primary" onclick="FC_payFull()">
                                    <i class="fa fa-check-circle"></i> Pay Full Due
                                </button>
                                <button type="button" class="pay-chip pay-chip-amber" onclick="FC_payCustom()">
                                    <i class="fa fa-pencil"></i> Custom
                                </button>
                            </div>
                            <div class="pay-amount-grid">
                                <div class="pay-field">
                                    <label class="pay-label" for="submitSchoolFees">
                                        School Fees (₹) <span class="fc-req">*</span>
                                    </label>
                                    <input type="number" id="submitSchoolFees" class="pay-input pay-input-primary"
                                        placeholder="0.00" step="0.01" min="0">
                                </div>
                                <div class="pay-field">
                                    <label class="pay-label" for="fineAmount">Fine (₹)</label>
                                    <input type="number" id="fineAmount" class="pay-input"
                                        placeholder="0.00" step="0.01" min="0">
                                </div>
                                <div class="pay-field">
                                    <label class="pay-label" for="reference">Reference / Remark</label>
                                    <input type="text" id="reference" class="pay-input"
                                        placeholder="e.g. Cash payment">
                                </div>
                            </div>
                        </div>

                        <!-- Row 3 — Calculation strip.
                             Full live-math flow so the cashier sees the effect
                             of what they just typed:
                               Total Fee − Discount − Paying Now = Balance After -->
                        <div class="pay-calc-strip">
                            <div class="pay-calc-item">
                                <span class="pay-calc-label">Total Fee</span>
                                <span class="pay-calc-val" id="barTotalFee">₹ 0.00</span>
                            </div>
                            <span class="pay-calc-op">−</span>
                            <div class="pay-calc-item">
                                <span class="pay-calc-label">Discount</span>
                                <span class="pay-calc-val pay-calc-green" id="barDiscount">₹ 0.00</span>
                            </div>
                            <span class="pay-calc-op">−</span>
                            <div class="pay-calc-item">
                                <span class="pay-calc-label">Paying Now</span>
                                <span class="pay-calc-val pay-calc-blue" id="barPayingNow">₹ 0.00</span>
                            </div>
                            <span class="pay-calc-op">=</span>
                            <div class="pay-calc-item pay-calc-item-strong">
                                <span class="pay-calc-label">Balance After</span>
                                <span class="pay-calc-val pay-calc-red" id="barDueAmount">₹ 0.00</span>
                            </div>
                        </div>

                        <!-- Row 4 — Live allocation preview -->
                        <div class="pay-alloc" id="allocPreview" style="display:none;">
                            <div class="pay-alloc-title">
                                <i class="fa fa-list-ol"></i>
                                <span>Allocation Preview</span>
                                <span class="pay-alloc-hint">how this payment will be applied</span>
                            </div>
                            <div class="fc-alloc-list" id="allocList"></div>
                            <div class="fc-alloc-advance" id="allocAdvance" style="display:none;"></div>
                        </div>

                        <!-- Row 5 — Actions -->
                        <div class="pay-actions">
                            <button type="button" class="pay-btn pay-btn-ghost"
                                onclick="location.href='<?= site_url('fees/fees_counter') ?>'">
                                <i class="fa fa-file-o"></i> New Receipt
                            </button>
                            <button type="button" id="submitFeesBtn" class="pay-btn pay-btn-submit">
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
                    placeholder="Type name / ID / father / class — or leave blank to see all" autocomplete="off">
                <button type="button" class="fc-btn fc-btn-primary" id="doSearchBtn"
                        onclick="if(typeof doSearch==='function') doSearch();">
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
                            <td colspan="7" class="fc-empty-cell">
                                <i class="fa fa-spinner fa-spin"></i> Loading roster…
                            </td>
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

<!-- ══ MODAL: History (redesigned — scoped via #historyModal) ══ -->
<div class="fc-overlay" id="historyModal" data-no-outside-close="1">
    <div class="fc-modal fc-modal-wide hist-modal">
        <div class="hist-head">
            <div class="hist-head-left">
                <div class="hist-head-icon"><i class="fa fa-history"></i></div>
                <div>
                    <h4>Payment History</h4>
                    <p class="hist-head-sub" id="historySubtitle">Complete transaction ledger for this student</p>
                </div>
            </div>
            <button class="hist-close" onclick="closeModal('historyModal')" aria-label="Close">
                <i class="fa fa-times"></i>
            </button>
        </div>

        <div class="hist-body">
            <!-- KPI chips — populated by loadHistory() -->
            <div class="hist-kpis" id="historyKpis" style="display:none;">
                <div class="hist-kpi">
                    <div class="hist-kpi-label">Receipts</div>
                    <div class="hist-kpi-val" id="kpiCount">—</div>
                </div>
                <div class="hist-kpi hist-kpi-input">
                    <div class="hist-kpi-label">Total Paid</div>
                    <div class="hist-kpi-val" id="kpiInput">₹ 0</div>
                    <div class="hist-kpi-sub">By parent / cashier</div>
                </div>
                <div class="hist-kpi hist-kpi-alloc">
                    <div class="hist-kpi-label">Allocated</div>
                    <div class="hist-kpi-val" id="kpiAlloc">₹ 0</div>
                    <div class="hist-kpi-sub">Applied to demands</div>
                </div>
                <div class="hist-kpi hist-kpi-rem">
                    <div class="hist-kpi-label">Outstanding</div>
                    <div class="hist-kpi-val" id="kpiRem">₹ 0</div>
                    <div class="hist-kpi-sub">After these payments</div>
                </div>
            </div>

            <!-- Table (sticky thead within the scroll area) -->
            <div class="hist-table-wrap">
                <table class="hist-table">
                    <thead>
                        <tr>
                            <th class="th-rcpt">Receipt</th>
                            <th class="th-date">Date</th>
                            <th class="th-months">Months</th>
                            <th class="th-status">Status</th>
                            <th class="th-mode">Mode</th>
                            <th class="th-num" title="What the parent/cashier paid">Input</th>
                            <th class="th-num" title="Applied to demands">Allocated</th>
                            <th class="th-num" title="Outstanding on the months touched by this receipt">Remaining</th>
                            <th class="th-num">Fine</th>
                            <th class="th-num">Discount</th>
                        </tr>
                    </thead>
                    <tbody id="historyTbody">
                        <tr>
                            <td colspan="10" class="hist-empty">
                                <div class="hist-empty-icon"><i class="fa fa-inbox"></i></div>
                                <div class="hist-empty-title">No history yet</div>
                                <div class="hist-empty-sub">Receipts will appear here as soon as the student has paid.</div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<!-- ══ MODAL: Grant / Edit Discount (inline) ══ -->
<div class="fc-overlay" id="grantDiscountModal">
    <div class="fc-modal grant-modal">
        <div class="grant-head">
            <div class="grant-head-left">
                <span class="grant-head-icon"><i class="fa fa-gift"></i></span>
                <div>
                    <h4>Grant Discount</h4>
                    <p class="grant-head-sub" id="grantHeadSub">For the selected student</p>
                </div>
            </div>
            <button class="hist-close" onclick="closeModal('grantDiscountModal')" aria-label="Close">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="grant-body">
            <div class="grant-field">
                <label class="grant-label" for="grantAmount">
                    Amount (₹) <span class="fc-req">*</span>
                </label>
                <input type="number" id="grantAmount" class="grant-input"
                       placeholder="0.00" step="0.01" min="0">
                <div class="grant-hint">Set to 0 to clear any existing discount.</div>
            </div>
            <div class="grant-field">
                <label class="grant-label" for="grantValidUntil">
                    Valid Until <span class="grant-opt">(optional)</span>
                </label>
                <input type="date" id="grantValidUntil" class="grant-input"
                       min="<?= date('Y-m-d') ?>">
                <div class="grant-hint">Leave blank for no expiry. Auto-clears past this date.</div>
            </div>
            <div class="grant-field">
                <label class="grant-label" for="grantReason">
                    Reason <span class="grant-opt">(optional)</span>
                </label>
                <input type="text" id="grantReason" class="grant-input"
                       placeholder="e.g. Sibling waiver, principal's discretion" maxlength="120">
            </div>
            <div class="grant-warn">
                <i class="fa fa-info-circle"></i>
                For repeatable rules (e.g. "Sibling 10% Off"), use
                <a href="<?= site_url('fee_management/discounts') ?>" target="_blank">Discount Policies</a>.
                For named scholarships, use
                <a href="<?= site_url('fee_management/scholarships') ?>" target="_blank">Scholarships</a>.
            </div>
        </div>
        <div class="grant-foot">
            <button type="button" class="pay-btn pay-btn-ghost" onclick="closeModal('grantDiscountModal')">
                Cancel
            </button>
            <button type="button" id="grantSubmitBtn" class="pay-btn pay-btn-submit" onclick="submitGrantDiscount()">
                <i class="fa fa-check"></i> Save Discount
            </button>
        </div>
    </div>
</div>

<!-- ══ MODAL: Grant / Edit Discount (inline) ══ -->
<div class="fc-overlay" id="grantDiscountModal">
    <div class="fc-modal grant-modal">
        <div class="grant-head">
            <div class="grant-head-left">
                <span class="grant-head-icon"><i class="fa fa-gift"></i></span>
                <div>
                    <h4>Grant Discount</h4>
                    <p class="grant-head-sub" id="grantHeadSub">For the selected student</p>
                </div>
            </div>
            <button class="hist-close" onclick="closeModal('grantDiscountModal')" aria-label="Close">
                <i class="fa fa-times"></i>
            </button>
        </div>
        <div class="grant-body">
            <div class="grant-field">
                <label class="grant-label" for="grantAmount">
                    Amount (₹) <span class="fc-req">*</span>
                </label>
                <input type="number" id="grantAmount" class="grant-input"
                       placeholder="0.00" step="0.01" min="0">
                <div class="grant-hint">Set to 0 to clear any existing discount.</div>
            </div>
            <div class="grant-field">
                <label class="grant-label" for="grantValidUntil">
                    Valid Until <span class="grant-opt">(optional)</span>
                </label>
                <input type="date" id="grantValidUntil" class="grant-input"
                       min="<?= date('Y-m-d') ?>">
                <div class="grant-hint">Leave blank for no expiry. Auto-clears past this date.</div>
            </div>
            <div class="grant-field">
                <label class="grant-label" for="grantReason">
                    Reason <span class="grant-opt">(optional)</span>
                </label>
                <input type="text" id="grantReason" class="grant-input"
                       placeholder="e.g. Sibling waiver, principal's discretion" maxlength="120">
            </div>
            <div class="grant-warn">
                <i class="fa fa-info-circle"></i>
                For repeatable rules (e.g. "Sibling 10% Off"), use
                <a href="<?= site_url('fee_management/discounts') ?>" target="_blank">Discount Policies</a>.
                For named scholarships, use
                <a href="<?= site_url('fee_management/scholarships') ?>" target="_blank">Scholarships</a>.
            </div>
        </div>
        <div class="grant-foot">
            <button type="button" class="pay-btn pay-btn-ghost" onclick="closeModal('grantDiscountModal')">
                Cancel
            </button>
            <button type="button" id="grantSubmitBtn" class="pay-btn pay-btn-submit" onclick="submitGrantDiscount()">
                <i class="fa fa-check"></i> Save Discount
            </button>
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

<!-- ══ FLOATING QUICK-PAY BAR ══
     Shows at bottom of viewport when Step 4 (Submit Payment) enters
     view AND a student is loaded. Lets the cashier act on the net due
     without scrolling back up to the sticky summary card. -->
<div id="quickPayBar" class="quick-pay-bar" style="display:none;">
    <div class="qpb-info">
        <div class="qpb-row">
            <span class="qpb-label">Student</span>
            <strong id="qpbStudent">—</strong>
        </div>
        <div class="qpb-row">
            <span class="qpb-label">Net Due</span>
            <strong id="qpbDue" class="qpb-due">₹ 0.00</strong>
        </div>
        <div class="qpb-row">
            <span class="qpb-label">Submitting</span>
            <strong id="qpbSubmitting" class="qpb-submit-amt">₹ 0.00</strong>
        </div>
    </div>
    <button type="button" id="qpbSubmitBtn" class="qpb-btn"
            onclick="if(typeof submitFees==='function') submitFees();">
        <i class="fa fa-paper-plane"></i> Submit Fees
    </button>
</div>

<!-- Floating Quick-Pay Bar removed per user request. The inline
     "Submit Fees" button inside Step 4 (pay-actions block) is now
     the sole submit control. CSS class rules (.qpb-*, .quick-pay-bar)
     are left in place — they're now dead-style but removing them is
     out of scope. -->

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

    // Click-outside to close — opt-out for any modal carrying
    // data-no-outside-close="1" so it can ONLY be closed via its
    // explicit close button (e.g. History — has its own × in the
    // header, so backdrop-click felt like an accidental dismiss).
    document.querySelectorAll('.fc-overlay').forEach(function(ov) {
        if (ov.dataset.noOutsideClose === '1') return;
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
        // FC.grandTotal is the REMAINING-after-paid amount for the selected
        // months (used by the form & Pay-Full). FC.grandGross is the
        // structure total (used wherever the label reads "Total Fee").
        var gross = (typeof FC.grandGross === 'number') ? FC.grandGross : FC.grandTotal;
        var due = Math.max(0, FC.grandTotal - FC.discountAmt);

        // Use combined class+section for display everywhere
        var displayClassSection = getDisplayClassSection();

        document.getElementById('statTotalFee').textContent = fmtRs(gross);
        document.getElementById('statAlreadyPaid').textContent = fmtRs(FC.alreadyPaid);
        document.getElementById('statDiscount').textContent = fmtRs(FC.discountAmt);
        document.getElementById('statDue').textContent = fmtRs(due);

        // Live math strip under Step 4. `due` above is the
        // current-outstanding (already nets alreadyPaid + discount).
        // Subtracting schoolFee gives the balance LEFT OVER AFTER this
        // submit — the cashier's real question when typing the amount.
        var balanceAfter = Math.max(0, due - schoolFee);
        document.getElementById('barTotalFee').textContent = fmtRs(gross);
        document.getElementById('barDiscount').textContent = fmtRs(FC.discountAmt);
        document.getElementById('barPayingNow').textContent = fmtRs(schoolFee);
        document.getElementById('barDueAmount').textContent = fmtRs(balanceAfter);

        // Mirror "due" into the Submit Payment header pill so the
        // cashier always sees the net payable in the card title strip.
        var phd = document.getElementById('payHeadDue');
        var phv = document.getElementById('payHeadDueVal');
        if (phd && phv) {
            phv.textContent = fmtRs(due);
            phd.style.display = (FC.selectedMonths && FC.selectedMonths.length) ? '' : 'none';
        }

        // Mirror into the floating quick-pay bar (visibility toggled
        // by the IntersectionObserver — see DOMContentLoaded block).
        // Floating Quick-Pay Bar DOM updaters removed — the bar itself
        // has been deleted from the HTML. Inline Submit button handles
        // all submit interactions now.

        // Mirror "due" into the Submit Payment header pill so the
        // cashier always sees the net payable in the card title strip.
        var phd = document.getElementById('payHeadDue');
        var phv = document.getElementById('payHeadDueVal');
        if (phd && phv) {
            phv.textContent = fmtRs(due);
            phd.style.display = (FC.selectedMonths && FC.selectedMonths.length) ? '' : 'none';
        }

        // Mirror into the floating quick-pay bar (visibility toggled
        // by the IntersectionObserver — see DOMContentLoaded block).
        var qpbStu = document.getElementById('qpbStudent');
        var qpbDue = document.getElementById('qpbDue');
        var qpbSub = document.getElementById('qpbSubmitting');
        var qpbBtn = document.getElementById('qpbSubmitBtn');
        if (qpbStu) qpbStu.textContent = FC.studentName ? (FC.studentName + ' (' + FC.userId + ')') : '—';
        if (qpbDue) qpbDue.textContent = fmtRs(due);
        if (qpbSub) qpbSub.textContent = fmtRs(schoolFee + fine);
        if (qpbBtn) qpbBtn.disabled = !FC.userId || !FC.selectedMonths || !FC.selectedMonths.length;

        document.getElementById('sumName').textContent = FC.studentName || '—';
        document.getElementById('sumClass').textContent = displayClassSection || '—';
        document.getElementById('sumMonths').textContent = FC.selectedMonths.length ? FC.selectedMonths.join(', ') : '—';
        document.getElementById('sumTotal').textContent = fmtRs(gross);
        document.getElementById('sumDiscountRow').textContent = fmtRs(FC.discountAmt);
        document.getElementById('sumDue').textContent = fmtRs(due);
        document.getElementById('sumFine').textContent = fmtRs(fine);
        document.getElementById('sumPayable').textContent = fmtRs(schoolFee + fine);
        document.getElementById('discountDisplay').value = '₹ ' + fmtNum(FC.discountAmt);

        // Discount banner — shows the discount-on-file amount + edit button.
        // Invite — shown when no discount is on file (after a student is
        // loaded) so the cashier can grant a one-off in one click.
        var dBanner  = document.getElementById('discountBanner');
        var dBAmt    = document.getElementById('discountBannerAmt');
        var dExpiry  = document.getElementById('discountBannerExpiry');
        var dInvite  = document.getElementById('grantDiscountInvite');
        var hasStudent = !!FC.userId;
        var hasDiscount = FC.discountAmt > 0.005;

        if (dBanner && dBAmt) {
            if (hasDiscount) {
                dBAmt.textContent = '₹ ' + fmtNum(FC.discountAmt);
                if (dExpiry) {
                    var vu = (FC.discountValidUntil || '').trim();
                    if (vu) {
                        dExpiry.textContent = '· valid until ' + vu;
                        dExpiry.style.display = '';
                    } else {
                        dExpiry.style.display = 'none';
                    }
                }
                dBanner.style.display = '';
            } else {
                dBanner.style.display = 'none';
            }
        }
        if (dInvite) {
            // Show invite ONLY after a student is loaded AND no discount.
            dInvite.style.display = (hasStudent && !hasDiscount) ? '' : 'none';
        }

        // Discount banner — shows the discount-on-file amount + edit button.
        // Invite — shown when no discount is on file (after a student is
        // loaded) so the cashier can grant a one-off in one click.
        var dBanner  = document.getElementById('discountBanner');
        var dBAmt    = document.getElementById('discountBannerAmt');
        var dExpiry  = document.getElementById('discountBannerExpiry');
        var dInvite  = document.getElementById('grantDiscountInvite');
        var hasStudent = !!FC.userId;
        var hasDiscount = FC.discountAmt > 0.005;

        if (dBanner && dBAmt) {
            if (hasDiscount) {
                dBAmt.textContent = '₹ ' + fmtNum(FC.discountAmt);
                if (dExpiry) {
                    var vu = (FC.discountValidUntil || '').trim();
                    if (vu) {
                        dExpiry.textContent = '· valid until ' + vu;
                        dExpiry.style.display = '';
                    } else {
                        dExpiry.style.display = 'none';
                    }
                }
                dBanner.style.display = '';
            } else {
                dBanner.style.display = 'none';
            }
        }
        if (dInvite) {
            // Show invite ONLY after a student is loaded AND no discount.
            dInvite.style.display = (hasStudent && !hasDiscount) ? '' : 'none';
        }
    }

    /* ── Allocation Preview ── */
    function updateAllocationPreview() {
        var pool = parseFloat(document.getElementById('submitSchoolFees').value) || 0;
        var container = document.getElementById('allocPreview');
        var list = document.getElementById('allocList');
        var advEl = document.getElementById('allocAdvance');
        var qpEl = document.getElementById('quickPayBtns');

        if (!FC.selectedMonths.length || pool <= 0 || !Object.keys(FC.monthFeeMap).length) {
            container.style.display = 'none';
            return;
        }

        container.style.display = '';
        if (qpEl) qpEl.style.display = '';
        var remaining = pool;
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

        // Overpayment gets a warning — backend rejects any submit amount
        // that exceeds total due (HTTP 409). There is no overflow sink.
        if (remaining > 0.01) {
            advEl.innerHTML = '<i class="fa fa-exclamation-triangle" style="color:#dc2626"></i> ₹ ' + fmtNum(remaining)
                + ' exceeds total due — reduce the amount. Overpayment is no longer accepted.';
            advEl.style.display = '';
        } else {
            advEl.style.display = 'none';
        }
    }

    function FC_payFull() {
        var due = Math.max(0, FC.grandTotal - FC.discountAmt);
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

    /* ── Month Tiles (3-state: unpaid / partial / paid) ──
     *
     * Server payload shape (after the fetch_months upgrade):
     *   {April: {paid:1, status:'paid', totalDue:2800, totalPaid:2800, remaining:0}, ... }
     *
     * Backwards-compatible fallback: if the server still returns the old
     * binary {April: 1} shape, treat 1 as paid and 0 as unpaid.
     */
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
        var openCount = 0; // unpaid + partial — what the parent still owes

        months.forEach(function(m) {
            var raw = monthFees[m];
            var status, totalDue = 0, totalPaid = 0, remaining = 0;
            if (raw && typeof raw === 'object') {
                status   = raw.status || (raw.paid === 1 ? 'paid' : 'unpaid');
                totalDue = parseFloat(raw.totalDue)  || 0;
                totalPaid= parseFloat(raw.totalPaid) || 0;
                remaining= parseFloat(raw.remaining) || Math.max(0, totalDue - totalPaid);
            } else {
                status = (raw === 1) ? 'paid' : 'unpaid';
            }

            var isPaid    = status === 'paid';
            var isPartial = status === 'partial';
            if (!isPaid) openCount++;

            var isYearly = (m === 'Yearly Fees');
            var tile = document.createElement('div');
            tile.className = 'fc-month-tile'
                + (isPaid ? ' paid' : '')
                + (isPartial ? ' partial' : '')
                + (isYearly ? ' yearly' : '');
            tile.dataset.month = m;

            // Status row + (when partial) progress + remaining label
            var statusHtml;
            if (isPaid) {
                statusHtml = '<i class="fa fa-check-circle" style="color:#16a34a"></i> Paid';
            } else if (isPartial) {
                // Display tiers — prevents "0%" showing when payment is
                // a tiny fraction of due (e.g. ₹1 of ₹3,800 = 0.026% →
                // Math.round = 0). Tiers:
                //   totalPaid == 0        → "0%"
                //   0 < raw < 1%          → "<1%"
                //   1% ≤ raw < 10%        → one decimal ("2.5%")
                //   raw ≥ 10%             → rounded integer ("42%")
                var raw = totalDue > 0 ? (totalPaid / totalDue) * 100 : 0;
                var pctLabel;
                if (totalPaid <= 0)       pctLabel = '0%';
                else if (raw < 1)         pctLabel = '<1%';
                else if (raw < 10)        pctLabel = raw.toFixed(1) + '%';
                else                       pctLabel = Math.round(raw) + '%';
                statusHtml =
                    '<i class="fa fa-clock-o" style="color:#f59e0b"></i> Partial · ' + pctLabel +
                    '<div style="font-size:11px;color:#dc2626;margin-top:2px">' +
                        '₹' + remaining.toLocaleString() + ' due' +
                    '</div>';
            } else {
                statusHtml = '<i class="fa fa-circle-o"></i> Unpaid';
            }

            // Yearly tile uses a friendlier label so the cashier knows
            // it's a one-time annual charge (not a 13th month).
            var nameLabel = isYearly
                ? 'Annual Fee'
                  + '<div style="font-size:10px;color:#0f766e;font-weight:500;margin-top:1px">(One-time)</div>'
                : m;
            tile.innerHTML =
                '<div class="fc-month-name">' + nameLabel + '</div>' +
                '<div class="fc-month-status">' + statusHtml + '</div>';

            // Allow selecting both unpaid AND partial — partial months
            // can take more payment to clear their remaining balance.
            if (!isPaid) {
                tile.addEventListener('click', function() {
                    var wasSelected = tile.classList.contains('selected');
                    tile.classList.toggle('selected');

                    // Auto-bundle: clicking April auto-ticks the Yearly
                    // Fees tile if it's unpaid — matches the Indian-school
                    // convention that the first-month bill includes
                    // annual heads. Cashier can still un-tick Yearly Fees
                    // explicitly if they want to collect April alone.
                    // No forced un-ticking — untoggling April doesn't
                    // remove a Yearly Fees selection the cashier made.
                    if (!wasSelected && m === 'April') {
                        var yft = grid.querySelector('.fc-month-tile.yearly');
                        if (yft && !yft.classList.contains('paid') && !yft.classList.contains('selected')) {
                            yft.classList.add('selected');
                        }
                    }

                    updateFromTiles();
                });
            }
            grid.appendChild(tile);
        });

        if (actions) actions.style.display = 'flex';
        if (hint) hint.textContent = openCount + ' open month(s)';
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

                // Phase 21: pull aggregate summary out and pre-populate the
                // stat strip + summary panel BEFORE the cashier picks any
                // month. Old shape (months as keys) is kept; _summary is
                // a sibling key that buildMonthTiles ignores.
                var summary = data._summary;
                if (summary) {
                    FC.grandGross         = parseFloat(summary.totalGross)  || 0;
                    FC.alreadyPaid        = parseFloat(summary.alreadyPaid) || 0;
                    FC.discountAmt        = parseFloat(summary.discount)    || 0;
                    FC.discountValidUntil = (summary.discountValidUntil || '').toString();
                    FC.discountExpired    = !!summary.discountExpired;
                    // grandTotal = REMAINING (what's still owed across
                    // all months). "Pay Full Due" later uses this minus
                    // discount.
                    FC.grandTotal         = parseFloat(summary.remaining)   || 0;
                    if (typeof recalc === 'function') recalc();
                    delete data._summary; // keep buildMonthTiles oblivious
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
        FC.grandTotal = FC.discountAmt = FC.alreadyPaid = 0;
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
        // d.grandTotal / d.monthTotals are now REMAINING-after-paid (server
        // subtracts feeDemands.paid_amount). d.grandGross / d.monthGross
        // carry the original structure totals (used by the breakdown modal).
        FC.grandTotal = parseFloat(d.grandTotal) || 0;
        FC.grandGross = parseFloat(d.grandGross) || FC.grandTotal;
        FC.discountAmt        = parseFloat(d.discountAmount) || 0;
        FC.discountValidUntil = (d.discountValidUntil || '').toString();
        FC.discountExpired    = !!d.discountExpired;
        FC.monthFeeMap = d.monthTotals || {};
        FC.monthGrossMap = d.monthGross || {};
        FC.monthAlreadyPaid = d.monthAlreadyPaid || {};

        // "Already Paid" for the SELECTED months = gross − remaining. This
        // gives the stat tile a correct value on fetch (without needing
        // the user to open the Payment History modal, which was the only
        // path that used to populate FC.alreadyPaid).
        FC.alreadyPaid = Math.max(0, FC.grandGross - FC.grandTotal);

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

        // Footer below the per-head rows must sum back to the row totals,
        // i.e. the GROSS structure total — not the remaining-after-paid
        // amount that the form uses.
        document.getElementById('breakdownGrandTotal').textContent = fmtNum(FC.grandGross);
        var h = document.getElementById('breakdownHeading');
        if (h) {
            var alreadyPaid = Math.max(0, FC.grandGross - FC.grandTotal);
            h.textContent = (d.message || '') + (alreadyPaid > 0
                ? ' · Already paid ₹' + fmtNum(alreadyPaid) + ' · Remaining ₹' + fmtNum(FC.grandTotal)
                : '');
        }

        document.getElementById('breakdownCard').style.display = '';
        document.getElementById('paymentCard').style.display = '';

        var due = Math.max(0, FC.grandTotal - FC.discountAmt);
        document.getElementById('submitSchoolFees').value = due.toFixed(2);

        // Modal shows the fee STRUCTURE per month — use gross totals so the
        // per-head columns sum back to the structure totals (not the
        // remaining-after-paid amounts which get used for the form/alloc).
        buildExpandModal(d.feeRecord, d.selectedMonths, d.monthGross || d.monthTotals, d.grandGross || d.grandTotal);
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
        // Empty term is now valid — server returns the full active
        // roster (capped at 200) so the cashier can browse without
        // typing. Useful as a "show all" affordance.
        var q = document.getElementById('searchInput').value.trim();

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
                            '<tr><td colspan="7" class="fc-empty-cell"><i class="fa fa-spinner fa-spin"></i> Loading roster…</td></tr>';
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
        // Validate first — toast + focus the offending field so the user
        // always sees why the form didn't submit, even when scrolled to the
        // bottom of the page.
        var focusAndFlash = function(elId) {
            var el = document.getElementById(elId);
            if (!el) return;
            el.scrollIntoView({ behavior:'smooth', block:'center' });
            el.focus();
            el.style.boxShadow = '0 0 0 3px rgba(239,68,68,.45)';
            setTimeout(function(){ el.style.boxShadow = ''; }, 1500);
        };
        if (!FC.userId) {
            showToast('Please select a student first.', 'error');
            focusAndFlash('user_id'); return;
        }
        if (!FC.selectedMonths.length) {
            showToast('Please select at least one month.', 'error');
            var grid = document.getElementById('monthGrid');
            if (grid) grid.scrollIntoView({ behavior:'smooth', block:'center' });
            return;
        }
        var paymentMode = document.getElementById('accountSelect').value;
        if (!paymentMode) {
            showToast('Please select a payment mode.', 'error');
            focusAndFlash('accountSelect'); return;
        }
        var schoolFees = parseFloat(document.getElementById('submitSchoolFees').value) || 0;
        if (schoolFees <= 0) {
            showToast('Please enter the fee amount.', 'error');
            focusAndFlash('submitSchoolFees'); return;
        }

        // Show confirmation modal instead of submitting directly
        var fineAmt = parseFloat(document.getElementById('fineAmount').value) || 0;
        var due = Math.max(0, FC.grandTotal - FC.discountAmt);
        var acctText = document.getElementById('accountSelect').options[document.getElementById('accountSelect').selectedIndex].text;

        // Derived figures for transparency
        var netDue           = Math.max(0, FC.grandTotal - FC.discountAmt);
        var appliedToDemands = Math.min(schoolFees, netDue);
        var remainingUnpaid  = Math.max(0, netDue - appliedToDemands);

        var row  = 'display:flex;justify-content:space-between;padding:6px 0;border-bottom:1px solid var(--fc-border)';
        var rowG = row + ';color:var(--fc-green)';
        var rowR = row + ';color:var(--fc-red)';
        var rowBig = 'display:flex;justify-content:space-between;padding:10px 0;font-size:16px';

        var ch = '<div style="font-size:13px;line-height:1.7;color:var(--fc-navy,#1a2940)">';
        ch += '<div style="'+row+'"><span>Student</span><strong>' + (FC.studentName || '—') + '</strong></div>';
        ch += '<div style="'+row+'"><span>Class</span><strong>' + getDisplayClassSection() + '</strong></div>';
        ch += '<div style="'+row+'"><span>Months</span><strong>' + FC.selectedMonths.join(', ') + '</strong></div>';
        ch += '<div style="'+row+'"><span>Payment Mode</span><strong>' + acctText + '</strong></div>';
        ch += '<div style="'+row+'"><span>Total Fee</span><strong>₹ ' + fmtNum(FC.grandTotal) + '</strong></div>';
        if (FC.discountAmt > 0) ch += '<div style="'+rowG+'"><span>Discount</span><strong>- ₹ ' + fmtNum(FC.discountAmt) + '</strong></div>';
        ch += '<div style="'+row+';font-weight:600"><span>Due</span><strong>₹ ' + fmtNum(netDue) + '</strong></div>';
        if (fineAmt > 0) ch += '<div style="'+rowR+'"><span>Fine</span><strong>+ ₹ ' + fmtNum(fineAmt) + '</strong></div>';
        ch += '<div style="'+rowBig+'"><span style="font-weight:700">Cash from Parent Now</span><strong style="color:var(--fc-teal);font-size:18px">₹ ' + fmtNum(schoolFees + fineAmt) + '</strong></div>';

        // After-submit summary — partial payments stay visible. Overpayment
        // is rejected upstream so the server never keeps a surplus.
        if (remainingUnpaid > 0.005) {
            ch += '<div style="'+rowR+';font-weight:700;border-top:1px dashed var(--fc-border);padding-top:8px"><span><i class="fa fa-exclamation-triangle"></i> Remaining Unpaid After This Receipt</span><strong>₹ ' + fmtNum(remainingUnpaid) + '</strong></div>';
        }
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
        fd.append('submitAmount', '0.00'); // No overflow sink — server rejects overpayment
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

        // Always reset the button no matter what — success, failure, or
        // silent JS exception in the success handler. Without this the
        // spinner gets stuck if any DOM update below throws (e.g. missing
        // modal element, corrupted response, etc.) because the catch
        // handler only fires on promise rejection, not synchronous
        // exceptions in .then() that we silently swallow.
        var resetBtn = function() {
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Fees';
        };

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
                // Robust parse: read as text first so a PHP warning leaking
                // before the JSON doesn't silently throw an unreadable
                // SyntaxError. Strip leading non-JSON output if present.
                return r.text().then(function(txt) {
                    var t = (txt || '').trim();
                    var firstBrace = t.indexOf('{');
                    if (firstBrace > 0) t = t.substring(firstBrace);
                    try { return JSON.parse(t); }
                    catch (e) {
                        console.error('submitFees: invalid JSON response', txt);
                        throw new Error('Server returned non-JSON response. Check PHP error log.');
                    }
                });
            })
            .then(function(resp) {
                try {
                    if (resp.status === 'success') {
                        showToast('Fees submitted successfully!', 'success');

                        var rn = resp.receipt_no || RECEIPT_NO;
                        // Phase 7D — block print while receipt is queued.
                        // In async mode, the worker flips status to "posted"
                        // within ~30 s; until then, the receipt isn't in
                        // the demands table so a printed copy would show
                        // allocations that may still shift.
                        var isQueued = (resp.receipt_status && resp.receipt_status !== 'posted')
                                     || resp.async === true;
                        var msgEl = document.getElementById('successMsg');
                        if (msgEl) {
                            var msg = 'Receipt #' + rn + ' — ₹ ' + fmtNum(parseFloat(document.getElementById('submitSchoolFees').value)||0) + ' collected from ' + FC.studentName;
                            if (isQueued) {
                                msg += '  ·  receipt still processing, please wait ~30 s before printing';
                            }
                            msgEl.textContent = msg;
                        }
                        var detailEl = document.getElementById('successDetail');
                        if (detailEl) detailEl.innerHTML =
                            '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span>Student</span><strong>' + FC.studentName + '</strong></div>'
                            + '<div style="display:flex;justify-content:space-between;margin-bottom:4px"><span>Months</span><strong>' + FC.selectedMonths.join(', ') + '</strong></div>'
                            + '<div style="display:flex;justify-content:space-between"><span>Receipt No.</span><strong>#' + rn + '</strong></div>'
                            + (isQueued ? '<div style="margin-top:8px;padding:6px 8px;border-radius:6px;background:#fff8e1;color:#7a5d00;font-size:12px;"><i class="fa fa-clock-o"></i> Status: <strong>Processing</strong> — the receipt is being posted in the background. Print will be enabled once posting completes.</div>' : '');
                        var printBtn = document.getElementById('successPrintBtn');
                        if (printBtn) {
                            printBtn.href = SITE_URL + '/fees/print_receipt/' + rn;
                            if (isQueued) {
                                printBtn.classList.add('fc-btn-disabled');
                                printBtn.setAttribute('aria-disabled', 'true');
                                printBtn.addEventListener('click', function(ev) {
                                    ev.preventDefault();
                                    showToast('Receipt still processing — please wait a few seconds and retry.', 'warning');
                                }, { once: false });
                            } else {
                                printBtn.classList.remove('fc-btn-disabled');
                                printBtn.removeAttribute('aria-disabled');
                            }
                        }
                        if (typeof openModal === 'function') openModal('successModal');

                        // ── Phase 7E — live receipt-status polling ────────
                        // If the sync response came back with status='queued'
                        // (async mode), poll /fees/receipt_status every 3 s
                        // until the worker flips it to 'posted', then:
                        //   - update the success modal's status badge
                        //   - re-enable the Print button
                        //   - auto-refresh the payment history so the new
                        //     row appears with the Completed pill
                        // A delayed-processing warning fires at 15 s.
                        if (isQueued) {
                            window.FC_POLL_ACTIVE && clearInterval(window.FC_POLL_ACTIVE);
                            var pollStartedAt = Date.now();
                            var printBtnEl    = document.getElementById('successPrintBtn');
                            var msgElPoll     = document.getElementById('successMsg');
                            var detailElPoll  = document.getElementById('successDetail');
                            var warnedDelay   = false;

                            function onReceiptPosted() {
                                if (printBtnEl) {
                                    printBtnEl.classList.remove('fc-btn-disabled');
                                    printBtnEl.removeAttribute('aria-disabled');
                                }
                                if (msgElPoll) {
                                    msgElPoll.textContent = 'Receipt #' + rn + ' posted — ready to print.';
                                }
                                if (detailElPoll) {
                                    // Replace the amber banner with a green "Completed" one.
                                    var banner = detailElPoll.querySelector('div:last-child');
                                    if (banner) {
                                        banner.style.background = '#ecfdf5';
                                        banner.style.color      = '#065f46';
                                        banner.innerHTML        = '<i class="fa fa-check-circle"></i> Status: <strong>Completed</strong> — the receipt is fully posted and refund-eligible.';
                                    }
                                }
                                // Auto-refresh history so the new row lands
                                // with its now-correct pill (Task 7).
                                if (typeof loadHistory === 'function' && typeof openModal === 'function') {
                                    // quiet refresh only if the history modal is currently open
                                    var histModal = document.getElementById('historyModal');
                                    if (histModal && histModal.classList.contains('active')) loadHistory();
                                }
                            }
                            function onReceiptFailed() {
                                if (msgElPoll) msgElPoll.textContent = 'Receipt #' + rn + ' processing FAILED — admin will investigate.';
                                if (detailElPoll) {
                                    var banner = detailElPoll.querySelector('div:last-child');
                                    if (banner) {
                                        banner.style.background = '#fee2e2';
                                        banner.style.color      = '#991b1b';
                                        banner.innerHTML        = '<i class="fa fa-exclamation-triangle"></i> Status: <strong>Failed</strong> — the receipt was recorded but background posting failed. Contact admin to retry via Queue Dashboard.';
                                    }
                                }
                            }

                            window.FC_POLL_ACTIVE = setInterval(function () {
                                var elapsed = Date.now() - pollStartedAt;
                                if (elapsed > 120000) {
                                    clearInterval(window.FC_POLL_ACTIVE);  // give up after 2 min
                                    if (msgElPoll) msgElPoll.textContent = 'Still processing… please refresh Payment History manually in a minute.';
                                    return;
                                }
                                if (elapsed > 15000 && !warnedDelay) {
                                    warnedDelay = true;
                                    showToast('Taking longer than expected. Your payment is safe — we\u2019re still posting it.', 'warning');
                                }
                                fetch(SITE_URL + '/fees/receipt_status?receipt_no=' + encodeURIComponent(rn), {
                                    credentials: 'same-origin',
                                })
                                .then(function (r) { return r.json(); })
                                .then(function (resp) {
                                    var d = (resp && resp.data) ? resp.data : resp;
                                    var st = d && d.status;
                                    if (st === 'posted') {
                                        clearInterval(window.FC_POLL_ACTIVE);
                                        onReceiptPosted();
                                    } else if (st === 'failed') {
                                        clearInterval(window.FC_POLL_ACTIVE);
                                        onReceiptFailed();
                                    }
                                })
                                .catch(function () { /* transient — next tick retries */ });
                            }, 3000);
                        }
                        // ─────────────────────────────────────────────────

                        ['breakdownCard', 'paymentCard', 'allocPreview', 'quickPayBtns'].forEach(function(id) {
                            var el = document.getElementById(id);
                            if (el) el.style.display = 'none';
                        });
                        document.querySelectorAll('.fc-month-tile').forEach(function(t) {
                            t.classList.remove('selected');
                        });
                        FC.selectedMonths = [];
                        FC.monthFeeMap = {};
                        FC.grandTotal = FC.discountAmt = 0;
                        if (typeof recalc === 'function') recalc();

                        /* Refresh receipt number — GET, no CSRF needed */
                        fetch(SITE_URL + '/fees/get_receipt_no')
                            .then(function(r) { return r.json(); })
                            .then(function(d) {
                                if (d && d.receiptNo) {
                                    RECEIPT_NO = d.receiptNo;
                                    var rEl = document.getElementById('receiptNo');       if (rEl) rEl.value = d.receiptNo;
                                    var tEl = document.getElementById('topReceiptNo');    if (tEl) tEl.textContent = d.receiptNo;
                                    var sEl = document.getElementById('sumReceiptNo');    if (sEl) sEl.textContent = d.receiptNo;
                                }
                            }).catch(function() {});

                        var acct = document.getElementById('accountSelect');
                        if (acct) acct.value = '';
                        var spm = document.getElementById('sumPaymentMode');
                        if (spm) spm.textContent = '—';

                        if (typeof fetchMonths === 'function') fetchMonths(FC.userId);
                        setTimeout(function() {
                            if (typeof loadHistory === 'function') loadHistory();
                        }, 800);
                    } else {
                        showAlert('Error: ' + (resp.message || 'Unknown error'), 'error');
                    }
                } finally {
                    // Guaranteed reset even if any DOM update above throws.
                    resetBtn();
                }
            })
            .catch(function(e) {
                console.error('submitFees:', e);
                showAlert((e && e.message) ? e.message : 'Network error during submission.', 'error');
                resetBtn();
            });
    }

    /* ── Payment History ── */
    function loadHistory() {
        if (!FC.userId) {
            showToast('Select a student first.', 'warning');
            return;
        }

        var tbody    = document.getElementById('historyTbody');
        var kpisWrap = document.getElementById('historyKpis');
        var subEl    = document.getElementById('historySubtitle');

        // Loading state — friendlier than a single line in the table.
        if (kpisWrap) kpisWrap.style.display = 'none';
        tbody.innerHTML =
            '<tr><td colspan="10" class="hist-empty">' +
              '<div class="hist-empty-icon"><i class="fa fa-spinner fa-spin"></i></div>' +
              '<div class="hist-empty-title">Loading payments…</div>' +
            '</td></tr>';
        openModal('historyModal');
        if (subEl) {
            subEl.textContent = 'Loading transactions for ' + (FC.studentName || FC.userId) + '…';
        }

        // Friendly mode label + matching pill class.
        function modePill(modeRaw) {
            var m = String(modeRaw || 'N/A').trim();
            var cls = 'mode-pill mode-other';
            var ml = m.toLowerCase();
            if (ml === 'razorpay' || ml.indexOf('online') >= 0)       cls = 'mode-pill mode-online';
            else if (ml === 'cash')                               cls = 'mode-pill mode-cash';
            else if (ml.indexOf('bank') >= 0 || ml.indexOf('transfer') >= 0) cls = 'mode-pill mode-bank';
            return '<span class="' + cls + '">' + m + '</span>';
        }

        postForm(SITE_URL + '/fees/fetch_fee_receipts', {
                userId: FC.userId
            })
            .then(function(data) {
                tbody.innerHTML = '';
                if (!data || !data.length) {
                    if (kpisWrap) kpisWrap.style.display = 'none';
                    if (subEl)    subEl.textContent = 'No payments on file for ' + (FC.studentName || FC.userId) + '.';
                    tbody.innerHTML =
                        '<tr><td colspan="10" class="hist-empty">' +
                          '<div class="hist-empty-icon"><i class="fa fa-inbox"></i></div>' +
                          '<div class="hist-empty-title">No payments yet</div>' +
                          '<div class="hist-empty-sub">Receipts will appear here once the student has paid.</div>' +
                        '</td></tr>';
                    return;
                }
                // Totals are NET (refunds reduce the running total so the
                // viewer sees the truth — F2 paid 1 + R2 refunded -1 + F3
                // paid 1 = net 1, not 2).
                var tInput = 0, tAlloc = 0, tRem = 0,
                    tFin = 0, tDis = 0,
                    receiptCount = 0, refundCount = 0;
                data.forEach(function(rec) {
                    // inputAmount for refunds is prefixed with '-' in the
                    // backend; parseFloat handles that. Totals net naturally.
                    var inp = parseFloat(String(rec.inputAmount || rec.amount || 0).replace(/,/g, ''));
                    var alc = parseFloat(String(rec.allocatedAmount || 0).replace(/,/g, ''));
                    var rem = parseFloat(String(rec.remainingAfter || 0).replace(/,/g, ''));
                    var fin = parseFloat(String(rec.fine || 0).replace(/,/g, ''));
                    var dis = parseFloat(String(rec.discount || 0).replace(/,/g, ''));
                    tInput += inp;
                    tAlloc += alc;
                    tRem   += rem;
                    tFin += fin;
                    tDis += dis;
                    if (rec.type === 'refund') refundCount++; else receiptCount++;

                    // Coverage pill: 'full' (green) | 'partial' (amber) |
                    // 'refunded' (red, strikethrough receipt) | 'refund' (negative row).
                    // Phase 7E: async receipt-level status overrides the
                    // coverage pill while the worker hasn't posted yet — a
                    // queued/failed receipt needs to be instantly visible
                    // before we care about full/partial.
                    var coverage = (rec.coverage || 'unknown');
                    var asyncSt  = String(rec.receiptStatus || 'posted').toLowerCase();
                    var statusPill;
                    if (asyncSt === 'queued') {
                        statusPill = '<span class="hist-pill hist-pill-partial" title="Worker is finalising this receipt — actions disabled until Completed."><i class="fa fa-hourglass-half"></i> Processing…</span>';
                    } else if (asyncSt === 'failed') {
                        statusPill = '<span class="hist-pill hist-pill-refunded" title="Background posting failed — admin must retry via Queue Dashboard."><i class="fa fa-exclamation-triangle"></i> Failed</span>';
                    } else if (coverage === 'full') {
                        statusPill = '<span class="hist-pill hist-pill-full"><i class="fa fa-check-circle"></i> Full</span>';
                    } else if (coverage === 'partial') {
                        statusPill = '<span class="hist-pill hist-pill-partial"><i class="fa fa-clock-o"></i> Partial</span>';
                    } else if (coverage === 'refunded') {
                        statusPill = '<span class="hist-pill hist-pill-refunded"><i class="fa fa-undo"></i> Refunded via ' + (rec.refundedByR || 'R?') + '</span>';
                    } else if (coverage === 'refund') {
                        statusPill = '<span class="hist-pill hist-pill-refund"><i class="fa fa-reply"></i> Refund</span>';
                    } else {
                        statusPill = '<span class="hist-pill hist-pill-unknown">—</span>';
                    }

                    var months = Array.isArray(rec.months) ? rec.months : [];
                    var monthsHtml = months.length
                        ? months.map(function(m){
                              var lbl = (m === 'Yearly Fees') ? 'Annual' : m;
                              var cls = (m === 'Yearly Fees') ? 'hist-month-chip yearly' : 'hist-month-chip';
                              return '<span class="' + cls + '">' + lbl + '</span>';
                          }).join('')
                        : '<span class="hist-dim">—</span>';

                    // With overpayment rejected upstream, allocated always
                    // equals input. Historical receipts that pre-date the
                    // removal may still show a gap — render it as a neutral
                    // "carry-forward" note rather than the legacy wallet tag.
                    var carry = Math.max(0, inp - alc);
                    var allocCell = '<span class="hist-num-strong">₹ ' + fmtNum(alc) + '</span>';
                    if (carry > 0.01) {
                        allocCell += '<div class="hist-num-tag carry-tag">+₹' + fmtNum(carry) + ' carry-fwd</div>';
                    }
                    var remCell = (rem > 0.01)
                        ? '<span class="hist-num-due">₹ ' + fmtNum(rem) + '</span>'
                        : '<span class="hist-num-clear">₹ 0</span>';

                    var tr = document.createElement('tr');
                    // Refund rows: red accent, receipt label already has "R"
                    // prefix from backend. Refunded receipts: receipt number
                    // with strikethrough so the viewer instantly sees it was
                    // reversed. Amounts stay as-is; negatives render with
                    // minus sign and a red color via the .hist-num-refund class.
                    var isRefund       = rec.type === 'refund';
                    var isRefunded     = rec.coverage === 'refunded';
                    if (isRefund)   tr.className = 'hist-row-refund';
                    if (isRefunded) tr.className = 'hist-row-refunded';

                    var rcptLabel = isRefund
                        ? '<span class="hist-rcpt hist-rcpt-refund">' + rec.receiptNo + '</span>'
                        : '<span class="hist-rcpt' + (isRefunded ? ' hist-rcpt-struck' : '') + '">F' + rec.receiptNo + '</span>';

                    var inputCell = isRefund
                        ? '<span class="hist-num-refund">− ₹ ' + fmtNum(Math.abs(inp)) + '</span>'
                        : '<span class="hist-num-strong">₹ ' + fmtNum(inp) + '</span>';

                    tr.innerHTML =
                        '<td>' + rcptLabel + '</td>' +
                        '<td class="hist-cell-date">' + (rec.date || '—') + '</td>' +
                        '<td>' + monthsHtml + '</td>' +
                        '<td>' + statusPill + '</td>' +
                        '<td>' + modePill(rec.account) + '</td>' +
                        '<td class="hist-num">' + inputCell + '</td>' +
                        '<td class="hist-num">' + allocCell + '</td>' +
                        '<td class="hist-num">' + remCell + '</td>' +
                        '<td class="hist-num hist-dim">' + (fin > 0 ? '₹ ' + fmtNum(fin) : '—') + '</td>' +
                        '<td class="hist-num hist-num-disc">' + (dis > 0 ? '₹ ' + fmtNum(dis) : '—') + '</td>';
                    tbody.appendChild(tr);
                });
                var tot = document.createElement('tr');
                tot.className = 'hist-total';
                // Remaining col is a per-row snapshot of demand balance
                // immediately after each receipt. Summing them across
                // receipts that touched the same demand is meaningless
                // (e.g., F2 → R2 → F3 on the same Library Fee demand yields
                // 299+0+299 = 598 which equals nothing real). The student's
                // actual current outstanding is in the OUTSTANDING KPI at
                // the top. Render "—" here instead of a misleading sum.
                tot.innerHTML =
                    '<td colspan="5"><strong>TOTAL</strong></td>' +
                    '<td class="hist-num"><strong>₹ ' + fmtNum(tInput) + '</strong></td>' +
                    '<td class="hist-num"><strong>₹ ' + fmtNum(tAlloc) + '</strong></td>' +
                    '<td class="hist-num hist-dim"><strong>—</strong></td>' +
                    '<td class="hist-num"><strong>' + (tFin > 0 ? '₹ ' + fmtNum(tFin) : '—') + '</strong></td>' +
                    '<td class="hist-num"><strong>' + (tDis > 0 ? '₹ ' + fmtNum(tDis) : '—') + '</strong></td>';
                tbody.appendChild(tot);

                // Populate KPI chips.
                if (kpisWrap) {
                    // "Receipts" KPI: show receipts count only (ignore refund rows)
                    // so the label meaning stays stable. If any refunds exist,
                    // surface the count as a small secondary note.
                    var countEl = document.getElementById('kpiCount');
                    countEl.textContent = receiptCount;
                    if (refundCount > 0) {
                        countEl.insertAdjacentHTML('afterend',
                            '<div class="hist-kpi-sub">' + refundCount + ' refund' + (refundCount === 1 ? '' : 's') + '</div>');
                    }
                    document.getElementById('kpiInput').textContent = '₹ ' + fmtNum(tInput);
                    document.getElementById('kpiAlloc').textContent = '₹ ' + fmtNum(tAlloc);
                    // Outstanding is the student's CURRENT total owed,
                    // read from fetch_months' canonical remaining. Do NOT
                    // sum per-receipt snapshots — double-counts when F2/R2/F3
                    // all touched the same demand.
                    var outstandingVal = (typeof FC.grandTotal === 'number' && FC.grandTotal >= 0)
                        ? FC.grandTotal
                        : tRem;
                    document.getElementById('kpiRem').textContent = '₹ ' + fmtNum(outstandingVal);
                    kpisWrap.style.display = '';
                }
                if (subEl) {
                    var subTxt = receiptCount + ' receipt' + (receiptCount === 1 ? '' : 's');
                    if (refundCount > 0) {
                        subTxt += ' + ' + refundCount + ' refund' + (refundCount === 1 ? '' : 's');
                    }
                    subTxt += ' for ' + (FC.studentName || FC.userId);
                    subEl.textContent = subTxt;
                }

                // NOTE: do NOT overwrite FC.alreadyPaid with tAlloc here.
                // tAlloc sums receipt.allocatedAmount (gross, doesn't net
                // refunds). fetch_months already set FC.alreadyPaid from
                // sum(demand.paidAmount) which IS refund-aware.
                recalc();
            })
            .catch(function(e) {
                console.error('loadHistory:', e);
                if (kpisWrap) kpisWrap.style.display = 'none';
                tbody.innerHTML =
                    '<tr><td colspan="10" class="hist-empty hist-empty-error">' +
                      '<div class="hist-empty-icon"><i class="fa fa-exclamation-triangle"></i></div>' +
                      '<div class="hist-empty-title">Couldn\'t load history</div>' +
                      '<div class="hist-empty-sub">Check your connection and try again.</div>' +
                    '</td></tr>';
            });
    }

    /* ── Grant / Edit Discount (inline) ── */
    function openGrantDiscountModal() {
        if (!FC.userId) {
            showToast('Select a student first.', 'warning');
            return;
        }
        // Pre-fill with current values so "edit" mode works.
        document.getElementById('grantAmount').value =
            FC.discountAmt > 0.005 ? FC.discountAmt.toFixed(2) : '';
        document.getElementById('grantValidUntil').value = FC.discountValidUntil || '';
        document.getElementById('grantReason').value = '';
        var sub = document.getElementById('grantHeadSub');
        if (sub) {
            sub.textContent = 'For ' + (FC.studentName || FC.userId)
                            + ' (' + (FC.userId || '—') + ')';
        }
        openModal('grantDiscountModal');
        setTimeout(function() {
            var el = document.getElementById('grantAmount');
            if (el) { el.focus(); el.select(); }
        }, 80);
    }

    function submitGrantDiscount() {
        if (!FC.userId) return;
        var amtRaw = document.getElementById('grantAmount').value;
        var amt    = parseFloat(amtRaw);
        if (isNaN(amt) || amt < 0) {
            showToast('Enter a valid amount (≥ 0).', 'error');
            return;
        }
        var vu     = document.getElementById('grantValidUntil').value;
        var reason = document.getElementById('grantReason').value.trim();

        var btn = document.getElementById('grantSubmitBtn');
        var origHtml = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

        var fd = new FormData();
        fd.append(CSRF_NAME, CSRF_HASH);
        fd.append('user_id',     FC.userId);
        fd.append('amount',      amt.toFixed(2));
        if (vu)     fd.append('valid_until', vu);
        if (reason) fd.append('reason',      reason);

        fetch(SITE_URL + '/fees/set_student_discount', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(r) { return r.json(); })
            .then(function(resp) {
                btn.disabled = false;
                btn.innerHTML = origHtml;
                if (!resp || !resp.success) {
                    showToast((resp && resp.message) || 'Failed to save discount.', 'error');
                    return;
                }
                showToast(resp.message || 'Discount saved.', 'success');
                closeModal('grantDiscountModal');

                // Reflect immediately in the UI without a full re-fetch
                // (which would also reset month selection). The user can
                // re-fetch fees to recompute due totals when ready.
                FC.discountAmt        = parseFloat(resp.amount) || 0;
                FC.discountValidUntil = (resp.validUntil || '').toString();
                FC.discountExpired    = false;
                if (typeof recalc === 'function') recalc();

                // Hint that allocation will only refresh on next fetch.
                if (FC.selectedMonths && FC.selectedMonths.length) {
                    showToast('Re-click "Fetch Fee Details" to recalc the due amount.', 'info');
                }
            })
            .catch(function(e) {
                console.error('set_student_discount:', e);
                btn.disabled = false;
                btn.innerHTML = origHtml;
                showToast('Network error while saving discount.', 'error');
            });
    }
    // Expose so onclick handlers can find them.
    window.openGrantDiscountModal = openGrantDiscountModal;
    window.submitGrantDiscount    = submitGrantDiscount;

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
            // Auto-load the full roster on open so the table is never
            // blank — cashier can scroll/filter without typing first.
            if (typeof doSearch === 'function') doSearch();
        });
        document.getElementById('doSearchBtn').addEventListener('click', doSearch);
        document.getElementById('searchInput').addEventListener('keydown', function(e) {
            if (e.key === 'Enter') doSearch();
        });
        // Debounced live filter as user types — re-runs the server
        // search 300ms after the last keystroke. Empty input is now a
        // valid query (returns the full roster), so backspacing all
        // the way doesn't lock the table.
        var __searchT = null;
        document.getElementById('searchInput').addEventListener('input', function() {
            clearTimeout(__searchT);
            __searchT = setTimeout(doSearch, 300);
        });
        // Debounced live filter as user types — re-runs the server
        // search 300ms after the last keystroke. Empty input is now a
        // valid query (returns the full roster), so backspacing all
        // the way doesn't lock the table.
        var __searchT = null;
        document.getElementById('searchInput').addEventListener('input', function() {
            clearTimeout(__searchT);
            __searchT = setTimeout(doSearch, 300);
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

        // Floating Quick-Pay bar observer removed — the bar itself is
        // gone. The inline Submit Fees button inside Step 4 is now the
        // single submit control.

        // ── Floating Quick-Pay bar — show when Step 4 (Submit Payment)
        //    enters viewport AND a student is loaded. Hides on scroll
        //    away. IntersectionObserver fires only when crossing the
        //    threshold so it's cheap. Falls back gracefully if the
        //    browser is too old (no observer = bar stays hidden).
        var qpBar = document.getElementById('quickPayBar');
        var step4 = document.getElementById('paymentCard');
        if (qpBar && step4 && 'IntersectionObserver' in window) {
            var qpObs = new IntersectionObserver(function(entries) {
                entries.forEach(function(entry) {
                    // Show when ≥40% of Step 4 is in view AND a student
                    // is loaded. Hide otherwise — keeps the bar out of
                    // the way at the top of the page.
                    var inView = entry.isIntersecting && entry.intersectionRatio >= 0.4;
                    if (inView && FC.userId) {
                        qpBar.classList.add('visible');
                        qpBar.style.display = 'flex';
                    } else {
                        qpBar.classList.remove('visible');
                        // Wait for the fade-out transition before hiding
                        // so the slide-down animation completes.
                        setTimeout(function() {
                            if (!qpBar.classList.contains('visible')) qpBar.style.display = 'none';
                        }, 250);
                    }
                });
            }, { threshold: [0, 0.4, 0.6, 1] });
            qpObs.observe(step4);
        }

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
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 16px;
        margin-bottom: 22px;
        padding: 16px 20px;
        background: linear-gradient(135deg, #0f1f3a 0%, #1a3358 100%);
        border-radius: 14px;
        box-shadow: 0 6px 22px rgba(15, 31, 58, .18);
    }

    .fc-page-title {
        font-family: 'Playfair Display', serif;
        font-size: 22px;
        font-weight: 700;
        color: #fff;
        display: flex;
        align-items: center;
        gap: 12px;
        margin: 0 0 4px;
        letter-spacing: .2px;
    }
    .fc-page-title i {
        color: #ffd479;
        background: rgba(255,255,255,.08);
        width: 36px;
        height: 36px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
    }

    .fc-breadcrumb {
        display: flex;
        gap: 6px;
        list-style: none;
        margin: 0;
        padding: 0 0 0 48px;
        font-size: 12px;
        color: rgba(255,255,255,.5);
    }
    .fc-breadcrumb a {
        color: #ffd479;
        text-decoration: none;
        font-weight: 500;
    }
    .fc-breadcrumb a:hover { color: #fef3c7; }
    .fc-breadcrumb li::before {
        content: '/';
        margin-right: 6px;
        color: rgba(255,255,255,.25);
    }
    .fc-breadcrumb li:first-child::before { display: none; }

    .fc-receipt-badge {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 2px;
        background: rgba(255, 212, 121, .12);
        border: 1px solid rgba(255, 212, 121, .25);
        padding: 9px 16px;
        border-radius: 10px;
    }

    .fc-receipt-label {
        font-size: 10.5px;
        font-weight: 700;
        letter-spacing: .6px;
        text-transform: uppercase;
        color: rgba(255, 212, 121, .85);
    }

    .fc-receipt-num {
        font-family: 'Playfair Display', serif;
        font-size: 24px;
        font-weight: 700;
        color: #ffd479;
        line-height: 1;
        font-variant-numeric: tabular-nums;
    }

    /* ── Stat strip (top KPI tiles) ─────────────────────────────────── */
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
        position: relative;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-left: 4px solid #94a3b8;
        border-radius: 12px;
        box-shadow: 0 2px 8px rgba(15, 31, 58, .04);
        padding: 14px 16px;
        display: flex;
        align-items: center;
        gap: 12px;
        transition: transform .15s, box-shadow .2s, border-color .2s;
        overflow: hidden;
    }

    .fc-stat:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 18px rgba(15, 31, 58, .08);
    }

    .fc-stat-icon {
        width: 44px;
        height: 44px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 17px;
        flex-shrink: 0;
    }

    .fc-stat-blue              { border-left-color: #2563eb; }
    .fc-stat-blue .fc-stat-icon { background: #dbeafe; color: #1d4ed8; }
    .fc-stat-blue .fc-stat-val  { color: #1d4ed8; }

    .fc-stat-green             { border-left-color: #16a34a; }
    .fc-stat-green .fc-stat-icon { background: #dcfce7; color: #15803d; }
    .fc-stat-green .fc-stat-val  { color: #15803d; }

    .fc-stat-amber             { border-left-color: #f59e0b; }
    .fc-stat-amber .fc-stat-icon { background: #fef3c7; color: #b45309; }
    .fc-stat-amber .fc-stat-val  { color: #b45309; }

    .fc-stat-red               { border-left-color: #dc2626; }
    .fc-stat-red .fc-stat-icon { background: #fee2e2; color: #b91c1c; }
    .fc-stat-red .fc-stat-val  { color: #b91c1c; }

    .fc-stat-label {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .6px;
        text-transform: uppercase;
        color: #64748b;
        margin-bottom: 4px;
    }

    .fc-stat-val {
        font-family: 'Playfair Display', serif;
        font-size: 19px;
        font-weight: 700;
        color: #0f1f3a;
        font-variant-numeric: tabular-nums;
        line-height: 1.1;
    }

    /* Layout */
    .fc-layout {
        display: grid;
        grid-template-columns: 1fr 320px;
        gap: 18px;
        /* IMPORTANT: do NOT set align-items: start here — the right
           column needs to stretch to the row's natural height so its
           inner sticky child has room to "stick" within. */
    }
    .fc-left  { min-width: 0; }   /* allow tables/inputs to shrink in narrow viewports */
    .fc-right { min-width: 0; }   /* same — and lets the sticky child compute layout */

    @media(max-width:960px) {
        .fc-layout {
            grid-template-columns: 1fr;
        }
        .fc-right {
            order: -1;        /* summary above the steps on mobile */
        }
        .fc-summary-card {
            position: static !important;   /* no sticky on small screens */
            max-height: none !important;
        }
    }

    /* Card */
    .fc-card {
        background: #fff;
        border-radius: 12px;
        border: 1px solid #e2e8f0;
        box-shadow: 0 2px 10px rgba(15, 31, 58, .04);
        margin-bottom: 16px;
        overflow: hidden;
        transition: box-shadow .2s;
    }
    .fc-card:hover { box-shadow: 0 4px 18px rgba(15, 31, 58, .08); }

    .fc-card-head {
        display: flex;
        align-items: center;
        gap: 11px;
        padding: 14px 20px;
        border-bottom: 1px solid #e2e8f0;
        background: linear-gradient(180deg, #fbfdff 0%, #f5f8fc 100%);
    }

    .fc-step {
        width: 28px;
        height: 28px;
        border-radius: 8px;
        background: linear-gradient(135deg, #0f1f3a 0%, #1a3358 100%);
        color: #ffd479;
        font-family: 'DM Sans', sans-serif;
        font-size: 13px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        box-shadow: 0 2px 6px rgba(15, 31, 58, .15);
    }

    .fc-card-head i {
        color: #0f766e;
        flex-shrink: 0;
        font-size: 14px;
    }

    .fc-card-head h3 {
        font-family: 'Playfair Display', serif;
        font-size: 15.5px;
        font-weight: 700;
        color: #0f1f3a;
        margin: 0;
        letter-spacing: .1px;
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
        height: 40px;
        padding: 0 12px;
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        font-size: 13.5px;
        color: #0f172a;
        background: #fff;
        font-family: 'DM Sans', sans-serif;
        outline: none;
        width: 100%;
        transition: border-color .15s, box-shadow .15s, background .15s;
    }
    .fc-input::placeholder { color: #cbd5e1; font-weight: 400; }

    .fc-input:focus,
    .fc-select:focus {
        border-color: #0f766e;
        box-shadow: 0 0 0 3px rgba(15, 118, 110, .14);
        background: #fff;
    }

    .fc-input[readonly] {
        background: #f8fafc;
        color: #475569;
        border-color: #e2e8f0;
        cursor: default;
    }

    .fc-input-green {
        color: #16a34a !important;
        font-weight: 700;
        background: #f0fdf4 !important;
        border-color: #86efac !important;
    }

    .fc-input-primary {
        border-color: #0f766e;
        font-weight: 600;
        background: #f0fdfa;
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

    /* Partial — orange tint, still selectable so admin can clear remainder. */
    .fc-month-tile.partial {
        background: #fffbeb;
        border-color: #fcd34d;
    }
    .fc-month-tile.partial:hover {
        background: #fef3c7;
        border-color: #f59e0b;
    }
    .fc-month-tile.partial .fc-month-status {
        color: #b45309;
    }
    .fc-month-tile.partial.selected {
        border-color: #d97706;
        box-shadow: 0 0 0 2px rgba(217, 119, 6, .25);
    }

    /* Yearly — teal accent so the cashier knows it's the once-per-session
       Annual Fee, not a 13th month. Still selectable; selection enforces
       the explicit-yearly rule the allocator now relies on (Phase 10). */
    .fc-month-tile.yearly {
        border-style: dashed;
        border-color: #5eead4;
        background: #f0fdfa;
    }
    .fc-month-tile.yearly:hover:not(.paid) {
        background: #ccfbf1;
        border-color: #14b8a6;
    }
    .fc-month-tile.yearly.selected {
        border-style: solid;
        border-color: #0f766e;
        box-shadow: 0 0 0 2px rgba(15, 118, 110, .25);
    }

    /* Yearly — teal accent so the cashier knows it's the once-per-session
       Annual Fee, not a 13th month. Still selectable; selection enforces
       the explicit-yearly rule the allocator now relies on (Phase 10). */
    .fc-month-tile.yearly {
        border-style: dashed;
        border-color: #5eead4;
        background: #f0fdfa;
    }
    .fc-month-tile.yearly:hover:not(.paid) {
        background: #ccfbf1;
        border-color: #14b8a6;
    }
    .fc-month-tile.yearly.selected {
        border-style: solid;
        border-color: #0f766e;
        box-shadow: 0 0 0 2px rgba(15, 118, 110, .25);
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

    /* Payment-history coverage pills (Full / Partial / unknown) */
    .fc-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 9px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .2px;
    }
    .fc-status-full     { background:#dcfce7; color:#166534; }
    .fc-status-partial  { background:#fef3c7; color:#92400e; }
    .fc-status-unknown  { background:#f1f5f9; color:#64748b; }

    /* Tiny chip for the Months column in the history modal */
    .fc-month-chip {
        display:inline-block;
        background:#eef2ff;
        color:#3730a3;
        padding:1px 7px;
        border-radius:10px;
        font-size:11px;
        font-weight:600;
        margin:1px 2px 1px 0;
    }

    /* Payment-history coverage pills (Full / Partial / unknown) */
    .fc-status-pill {
        display: inline-flex;
        align-items: center;
        gap: 4px;
        padding: 2px 9px;
        border-radius: 20px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .2px;
    }
    .fc-status-full     { background:#dcfce7; color:#166534; }
    .fc-status-partial  { background:#fef3c7; color:#92400e; }
    .fc-status-unknown  { background:#f1f5f9; color:#64748b; }

    /* Tiny chip for the Months column in the history modal */
    .fc-month-chip {
        display:inline-block;
        background:#eef2ff;
        color:#3730a3;
        padding:1px 7px;
        border-radius:10px;
        font-size:11px;
        font-weight:600;
        margin:1px 2px 1px 0;
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
        background: linear-gradient(135deg, #0f766e 0%, #0d5a55 100%);
        color: #fff;
        box-shadow: 0 2px 8px rgba(15, 118, 110, .25);
    }

    .fc-btn-amber {
        background: linear-gradient(135deg, #f59e0b 0%, #d97706 100%);
        color: #fff;
        box-shadow: 0 2px 8px rgba(245, 158, 11, .25);
    }

    .fc-btn-ghost {
        background: #fff;
        color: #475569;
        border: 1.5px solid #cbd5e1;
    }

    .fc-btn-ghost:hover {
        border-color: #0f766e;
        color: #0f766e;
        background: #f0fdfa;
        opacity: 1;
    }

    .fc-btn-info {
        background: #2563eb;
        color: #fff;
    }

    .fc-btn-submit {
        background: linear-gradient(135deg, #0f766e 0%, #0d5a55 100%);
        color: #fff;
        box-shadow: 0 4px 12px rgba(15, 118, 110, .3);
        font-size: 14px;
        padding: 10px 24px;
    }

    .fc-btn-submit:hover {
        opacity: .94;
        box-shadow: 0 6px 16px rgba(15, 118, 110, .4);
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
    /* `.fc-right` is the grid CELL — it stretches with the row.
       The sticky behaviour lives on the inner `.fc-summary-card` so it
       can position relative to the (taller) grid row, NOT the (shrunk)
       cell. Putting sticky on the cell itself caused the card to
       scroll away with the page because the cell was shrink-wrapped. */

    .fc-summary-card {
        position: sticky;
        top: 16px;
        max-height: calc(100vh - 32px);
        overflow-y: auto;       /* scroll inside the card if it ever
                                   exceeds the viewport (very long
                                   summaries, mobile-zoom, etc.) */
        background: linear-gradient(180deg, #0f1f3a 0%, #0a1730 100%);
        border-radius: 14px;
        box-shadow: 0 8px 28px rgba(15, 31, 58, .22);
        border: 1px solid rgba(255,255,255,.04);
    }
    /* Custom dark scrollbar so it doesn't clash with the navy card */
    .fc-summary-card::-webkit-scrollbar { width: 6px; }
    .fc-summary-card::-webkit-scrollbar-thumb {
        background: rgba(255,255,255,.18);
        border-radius: 3px;
    }
    .fc-summary-card::-webkit-scrollbar-track { background: transparent; }

    .fc-summary-head {
        padding: 14px 18px;
        color: #fff;
        font-size: 12.5px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
        border-bottom: 1px solid rgba(255,255,255,.08);
        display: flex;
        align-items: center;
        gap: 9px;
        background: linear-gradient(135deg, rgba(255,212,121,.12) 0%, rgba(15,118,110,.10) 100%);
    }
    .fc-summary-head i { color: #ffd479; }

    .fc-summary-body {
        padding: 14px 18px 16px;
    }

    .fc-summary-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid rgba(255,255,255,.06);
        font-size: 12.5px;
        color: rgba(255,255,255,.55);
        font-weight: 500;
    }
    .fc-summary-row:last-of-type { border-bottom: none; }

    .fc-summary-row strong {
        color: #fff;
        font-size: 13px;
        font-weight: 600;
        font-variant-numeric: tabular-nums;
    }

    .fc-summary-row.fc-green strong { color: #4ade80; }

    .fc-summary-divider {
        border-bottom: 1px dashed rgba(255,255,255,.10);
        margin: 8px 0;
    }

    .fc-summary-due {
        color: #fca5a5;
        background: rgba(220, 38, 38, .14);
        margin: 6px -18px;
        padding: 11px 18px !important;
        border: none !important;
        border-left: 3px solid #ef4444 !important;
    }
    .fc-summary-due strong {
        color: #fca5a5 !important;
        font-size: 18px !important;
        font-family: 'Playfair Display', serif;
    }

    .fc-summary-payable {
        background: rgba(255, 212, 121, .12);
        margin: 6px -18px 0;
        padding: 11px 18px !important;
        border: none !important;
        border-left: 3px solid #f59e0b !important;
    }
    .fc-summary-payable strong {
        color: #ffd479 !important;
        font-size: 17px !important;
        font-family: 'Playfair Display', serif;
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

    /* ── Discount/Scholarship banner (Step 1, conditional) ─────────── */
    .fc-disc-banner {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-top: 12px;
        padding: 11px 14px;
        background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
        border: 1px solid #bbf7d0;
        border-left: 4px solid #16a34a;
        border-radius: 10px;
    }
    .fc-disc-edit-btn {
        background: #fff;
        color: #15803d;
        border: 1.5px solid #86efac;
        border-radius: 8px;
        padding: 7px 12px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: background .15s, transform .12s;
        flex-shrink: 0;
    }
    .fc-disc-edit-btn:hover {
        background: #dcfce7;
        border-color: #16a34a;
    }
    .fc-disc-edit-btn:active { transform: scale(.96); }
    .fc-disc-expiry {
        font-size: 11px;
        font-weight: 500;
        color: #92400e;
        background: #fef3c7;
        padding: 2px 7px;
        border-radius: 10px;
        margin-left: 8px;
        font-family: 'DM Sans', sans-serif;
    }

    /* "No discount on file → grant one-off" invite line */
    .fc-grant-invite {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-top: 10px;
        padding: 8px 12px;
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
        font-size: 12.5px;
        color: #64748b;
    }
    .fc-grant-icon {
        width: 22px; height: 22px;
        border-radius: 6px;
        background: #e0f2fe;
        color: #0369a1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
    }
    .fc-grant-link {
        background: none;
        border: none;
        color: #0f766e;
        font-weight: 700;
        font-size: 12.5px;
        cursor: pointer;
        padding: 0;
        margin-left: auto;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .fc-grant-link:hover { color: #115e59; text-decoration: underline; }

    /* Grant Discount modal (reuses .pay-btn from Step 4) */
    .grant-modal {
        max-width: 520px;
        max-height: 92vh;
        display: flex;
        flex-direction: column;
        padding: 0;
        overflow: hidden;
    }
    .grant-head {
        background: linear-gradient(135deg, #0f1f3a 0%, #1a3358 100%);
        color: #fff;
        padding: 16px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-shrink: 0;
    }
    .grant-head-left {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .grant-head-icon {
        width: 36px; height: 36px;
        border-radius: 9px;
        background: rgba(255,212,121,.14);
        color: #ffd479;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
        flex-shrink: 0;
    }
    .grant-head h4 {
        font-family: 'Playfair Display', serif;
        font-size: 17px;
        font-weight: 700;
        color: #fff;
        margin: 0 0 1px;
    }
    .grant-head-sub {
        font-size: 12px;
        color: rgba(255,255,255,.65);
        margin: 0;
    }
    .grant-body {
        padding: 18px 20px;
        overflow: auto;
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .grant-field {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    .grant-label {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #475569;
    }
    .grant-opt {
        font-weight: 500;
        color: #94a3b8;
        text-transform: none;
        letter-spacing: 0;
    }
    .grant-input {
        padding: 10px 12px;
        font-size: 14px;
        font-weight: 500;
        color: #0f172a;
        background: #fff;
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        font-variant-numeric: tabular-nums;
        transition: border-color .15s, box-shadow .15s;
    }
    .grant-input:focus {
        outline: none;
        border-color: #0f766e;
        box-shadow: 0 0 0 3px rgba(15,118,110,.14);
    }
    .grant-hint {
        font-size: 11px;
        color: #64748b;
    }
    .grant-warn {
        background: #f0f9ff;
        border: 1px solid #bae6fd;
        border-left: 3px solid #0284c7;
        padding: 10px 12px;
        border-radius: 8px;
        font-size: 11.5px;
        color: #075985;
        line-height: 1.5;
    }
    .grant-warn i { margin-right: 4px; }
    .grant-warn a { color: #0369a1; font-weight: 700; text-decoration: none; }
    .grant-warn a:hover { text-decoration: underline; }
    .grant-foot {
        padding: 14px 20px;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        flex-shrink: 0;
    }
    .fc-disc-edit-btn {
        background: #fff;
        color: #15803d;
        border: 1.5px solid #86efac;
        border-radius: 8px;
        padding: 7px 12px;
        font-size: 12px;
        font-weight: 700;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        gap: 5px;
        transition: background .15s, transform .12s;
        flex-shrink: 0;
    }
    .fc-disc-edit-btn:hover {
        background: #dcfce7;
        border-color: #16a34a;
    }
    .fc-disc-edit-btn:active { transform: scale(.96); }
    .fc-disc-expiry {
        font-size: 11px;
        font-weight: 500;
        color: #92400e;
        background: #fef3c7;
        padding: 2px 7px;
        border-radius: 10px;
        margin-left: 8px;
        font-family: 'DM Sans', sans-serif;
    }

    /* "No discount on file → grant one-off" invite line */
    .fc-grant-invite {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        margin-top: 10px;
        padding: 8px 12px;
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 8px;
        font-size: 12.5px;
        color: #64748b;
    }
    .fc-grant-icon {
        width: 22px; height: 22px;
        border-radius: 6px;
        background: #e0f2fe;
        color: #0369a1;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
    }
    .fc-grant-link {
        background: none;
        border: none;
        color: #0f766e;
        font-weight: 700;
        font-size: 12.5px;
        cursor: pointer;
        padding: 0;
        margin-left: auto;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .fc-grant-link:hover { color: #115e59; text-decoration: underline; }

    /* Grant Discount modal (reuses .pay-btn from Step 4) */
    .grant-modal {
        max-width: 520px;
        max-height: 92vh;
        display: flex;
        flex-direction: column;
        padding: 0;
        overflow: hidden;
    }
    .grant-head {
        background: linear-gradient(135deg, #0f1f3a 0%, #1a3358 100%);
        color: #fff;
        padding: 16px 20px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        flex-shrink: 0;
    }
    .grant-head-left {
        display: flex;
        align-items: center;
        gap: 12px;
    }
    .grant-head-icon {
        width: 36px; height: 36px;
        border-radius: 9px;
        background: rgba(255,212,121,.14);
        color: #ffd479;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
        flex-shrink: 0;
    }
    .grant-head h4 {
        font-family: 'Playfair Display', serif;
        font-size: 17px;
        font-weight: 700;
        color: #fff;
        margin: 0 0 1px;
    }
    .grant-head-sub {
        font-size: 12px;
        color: rgba(255,255,255,.65);
        margin: 0;
    }
    .grant-body {
        padding: 18px 20px;
        overflow: auto;
        display: flex;
        flex-direction: column;
        gap: 14px;
    }
    .grant-field {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    .grant-label {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #475569;
    }
    .grant-opt {
        font-weight: 500;
        color: #94a3b8;
        text-transform: none;
        letter-spacing: 0;
    }
    .grant-input {
        padding: 10px 12px;
        font-size: 14px;
        font-weight: 500;
        color: #0f172a;
        background: #fff;
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        font-variant-numeric: tabular-nums;
        transition: border-color .15s, box-shadow .15s;
    }
    .grant-input:focus {
        outline: none;
        border-color: #0f766e;
        box-shadow: 0 0 0 3px rgba(15,118,110,.14);
    }
    .grant-hint {
        font-size: 11px;
        color: #64748b;
    }
    .grant-warn {
        background: #f0f9ff;
        border: 1px solid #bae6fd;
        border-left: 3px solid #0284c7;
        padding: 10px 12px;
        border-radius: 8px;
        font-size: 11.5px;
        color: #075985;
        line-height: 1.5;
    }
    .grant-warn i { margin-right: 4px; }
    .grant-warn a { color: #0369a1; font-weight: 700; text-decoration: none; }
    .grant-warn a:hover { text-decoration: underline; }
    .grant-foot {
        padding: 14px 20px;
        background: #f8fafc;
        border-top: 1px solid #e2e8f0;
        display: flex;
        justify-content: flex-end;
        gap: 10px;
        flex-shrink: 0;
    }
    .fc-disc-icon {
        width: 36px; height: 36px;
        border-radius: 9px;
        background: #dcfce7;
        color: #15803d;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
        flex-shrink: 0;
    }
    .fc-disc-body { flex: 1; min-width: 0; }
    .fc-disc-title {
        font-size: 13px;
        font-weight: 600;
        color: #14532d;
        margin-bottom: 2px;
    }
    .fc-disc-title strong {
        font-family: 'Playfair Display', serif;
        font-size: 15px;
        font-weight: 700;
        color: #15803d;
        margin-left: 4px;
    }
    .fc-disc-sub {
        font-size: 11.5px;
        color: #4d7c5f;
        line-height: 1.5;
    }
    .fc-disc-link {
        color: #15803d;
        font-weight: 700;
        text-decoration: none;
        border-bottom: 1px dashed currentColor;
    }
    .fc-disc-link:hover {
        color: #166534;
        border-bottom-style: solid;
    }

    /* ══════════════════════════════════════════════════════════════════
       FLOATING QUICK-PAY BAR — appears when scrolled near Step 4.
       Mirrors the Net Due + Submitting amount from the right summary
       so the cashier doesn't have to scroll back up to act.
       ══════════════════════════════════════════════════════════════════ */
    .quick-pay-bar {
        position: fixed;
        bottom: 18px;
        left: 50%;
        transform: translateX(-50%) translateY(20px);
        z-index: 7000;
        display: flex;
        align-items: center;
        gap: 24px;
        padding: 12px 16px 12px 22px;
        background: linear-gradient(135deg, #0f1f3a 0%, #1a3358 100%);
        border: 1px solid rgba(255, 212, 121, .25);
        border-radius: 14px;
        box-shadow: 0 12px 32px rgba(15, 31, 58, .35),
                    0 4px 12px rgba(15, 31, 58, .15);
        opacity: 0;
        pointer-events: none;
        transition: opacity .2s ease, transform .25s ease;
    }
    .quick-pay-bar.visible {
        opacity: 1;
        pointer-events: auto;
        transform: translateX(-50%) translateY(0);
    }

    .qpb-info {
        display: flex;
        align-items: center;
        gap: 22px;
        color: #fff;
    }
    .qpb-row {
        display: flex;
        flex-direction: column;
        gap: 2px;
        line-height: 1.1;
    }
    .qpb-label {
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .55px;
        color: rgba(255, 255, 255, .55);
    }
    .qpb-row strong {
        font-size: 14px;
        font-weight: 700;
        color: #fff;
        font-variant-numeric: tabular-nums;
    }
    .qpb-due {
        color: #fca5a5 !important;
        font-family: 'Playfair Display', serif;
        font-size: 17px !important;
    }
    .qpb-submit-amt {
        color: #ffd479 !important;
        font-family: 'Playfair Display', serif;
        font-size: 17px !important;
    }

    .qpb-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 11px 22px;
        border: none;
        border-radius: 10px;
        background: linear-gradient(135deg, #0f766e 0%, #0d5a55 100%);
        color: #fff;
        font-family: 'DM Sans', sans-serif;
        font-size: 13.5px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(15, 118, 110, .35);
        transition: transform .12s ease, box-shadow .15s ease;
    }
    .qpb-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(15, 118, 110, .45);
    }
    .qpb-btn:active { transform: translateY(0); }
    .qpb-btn:disabled {
        background: #94a3b8;
        cursor: not-allowed;
        box-shadow: none;
        transform: none;
    }

    @media (max-width: 720px) {
        .quick-pay-bar {
            left: 12px; right: 12px;
            transform: translateY(20px);
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
        }
        .quick-pay-bar.visible { transform: translateY(0); }
        .qpb-info { gap: 12px; }
        .qpb-row strong { font-size: 13px; }
        .qpb-due, .qpb-submit-amt { font-size: 15px !important; }
        .qpb-btn { padding: 9px 14px; font-size: 12.5px; }
    }

    /* ── Discount/Scholarship banner (Step 1, conditional) ─────────── */
    .fc-disc-banner {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-top: 12px;
        padding: 11px 14px;
        background: linear-gradient(135deg, #f0fdf4 0%, #ecfdf5 100%);
        border: 1px solid #bbf7d0;
        border-left: 4px solid #16a34a;
        border-radius: 10px;
    }
    .fc-disc-icon {
        width: 36px; height: 36px;
        border-radius: 9px;
        background: #dcfce7;
        color: #15803d;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 15px;
        flex-shrink: 0;
    }
    .fc-disc-body { flex: 1; min-width: 0; }
    .fc-disc-title {
        font-size: 13px;
        font-weight: 600;
        color: #14532d;
        margin-bottom: 2px;
    }
    .fc-disc-title strong {
        font-family: 'Playfair Display', serif;
        font-size: 15px;
        font-weight: 700;
        color: #15803d;
        margin-left: 4px;
    }
    .fc-disc-sub {
        font-size: 11.5px;
        color: #4d7c5f;
        line-height: 1.5;
    }
    .fc-disc-link {
        color: #15803d;
        font-weight: 700;
        text-decoration: none;
        border-bottom: 1px dashed currentColor;
    }
    .fc-disc-link:hover {
        color: #166534;
        border-bottom-style: solid;
    }

    /* ══════════════════════════════════════════════════════════════════
       FLOATING QUICK-PAY BAR — appears when scrolled near Step 4.
       Mirrors the Net Due + Submitting amount from the right summary
       so the cashier doesn't have to scroll back up to act.
       ══════════════════════════════════════════════════════════════════ */
    .quick-pay-bar {
        position: fixed;
        bottom: 18px;
        left: 50%;
        transform: translateX(-50%) translateY(20px);
        z-index: 7000;
        display: flex;
        align-items: center;
        gap: 24px;
        padding: 12px 16px 12px 22px;
        background: linear-gradient(135deg, #0f1f3a 0%, #1a3358 100%);
        border: 1px solid rgba(255, 212, 121, .25);
        border-radius: 14px;
        box-shadow: 0 12px 32px rgba(15, 31, 58, .35),
                    0 4px 12px rgba(15, 31, 58, .15);
        opacity: 0;
        pointer-events: none;
        transition: opacity .2s ease, transform .25s ease;
    }
    .quick-pay-bar.visible {
        opacity: 1;
        pointer-events: auto;
        transform: translateX(-50%) translateY(0);
    }

    .qpb-info {
        display: flex;
        align-items: center;
        gap: 22px;
        color: #fff;
    }
    .qpb-row {
        display: flex;
        flex-direction: column;
        gap: 2px;
        line-height: 1.1;
    }
    .qpb-label {
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .55px;
        color: rgba(255, 255, 255, .55);
    }
    .qpb-row strong {
        font-size: 14px;
        font-weight: 700;
        color: #fff;
        font-variant-numeric: tabular-nums;
    }
    .qpb-due {
        color: #fca5a5 !important;
        font-family: 'Playfair Display', serif;
        font-size: 17px !important;
    }
    .qpb-submit-amt {
        color: #ffd479 !important;
        font-family: 'Playfair Display', serif;
        font-size: 17px !important;
    }

    .qpb-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 11px 22px;
        border: none;
        border-radius: 10px;
        background: linear-gradient(135deg, #0f766e 0%, #0d5a55 100%);
        color: #fff;
        font-family: 'DM Sans', sans-serif;
        font-size: 13.5px;
        font-weight: 700;
        cursor: pointer;
        box-shadow: 0 4px 12px rgba(15, 118, 110, .35);
        transition: transform .12s ease, box-shadow .15s ease;
    }
    .qpb-btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(15, 118, 110, .45);
    }
    .qpb-btn:active { transform: translateY(0); }
    .qpb-btn:disabled {
        background: #94a3b8;
        cursor: not-allowed;
        box-shadow: none;
        transform: none;
    }

    @media (max-width: 720px) {
        .quick-pay-bar {
            left: 12px; right: 12px;
            transform: translateY(20px);
            justify-content: space-between;
            gap: 10px;
            padding: 10px 12px;
        }
        .quick-pay-bar.visible { transform: translateY(0); }
        .qpb-info { gap: 12px; }
        .qpb-row strong { font-size: 13px; }
        .qpb-due, .qpb-submit-amt { font-size: 15px !important; }
        .qpb-btn { padding: 9px 14px; font-size: 12.5px; }
    }

    /* ══════════════════════════════════════════════════════════════════
       SUBMIT PAYMENT (Step 4) — REDESIGNED
       Scoped under .pay-card so it overrides ONLY Step 4. Other .fc-card
       sections (Breakdown, Tiles) keep their existing look.
       ══════════════════════════════════════════════════════════════════ */
    .pay-card {
        border: 1px solid #e2e8f0;
        background: #fff;
        box-shadow: 0 4px 18px rgba(15, 31, 58, .06);
        overflow: hidden;
    }

    /* ── Header strip ───────────────────────────────────────────────── */
    .pay-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 14px 20px;
        background: linear-gradient(135deg, #0f1f3a 0%, #1a3358 100%);
        color: #fff;
    }
    .pay-head-left {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }
    .pay-step {
        width: 30px;
        height: 30px;
        border-radius: 8px;
        background: rgba(255,255,255,.10);
        color: #ffd479;
        font-size: 13px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-family: 'DM Sans', sans-serif;
    }
    .pay-head h3 {
        font-family: 'Playfair Display', serif;
        font-size: 16px;
        font-weight: 700;
        color: #fff;
        margin: 0 0 1px;
        letter-spacing: .2px;
    }
    .pay-head-sub {
        font-size: 12px;
        margin: 0;
        color: rgba(255,255,255,.6);
        font-weight: 500;
    }
    .pay-head-due {
        display: flex;
        align-items: center;
        gap: 8px;
        background: rgba(255, 212, 121, .14);
        border: 1px solid rgba(255, 212, 121, .25);
        padding: 7px 14px;
        border-radius: 999px;
        flex-shrink: 0;
    }
    .pay-head-due-label {
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: rgba(255,212,121,.85);
    }
    .pay-head-due-val {
        font-family: 'Playfair Display', serif;
        font-size: 16px;
        font-weight: 700;
        color: #ffd479;
        font-variant-numeric: tabular-nums;
    }

    /* ── Body ───────────────────────────────────────────────────────── */
    .pay-body {
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 18px;
    }

    /* ── Section (groups: Mode / Amount) ────────────────────────────── */
    .pay-section {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px 16px;
    }
    .pay-section-head {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #0f1f3a;
    }
    .pay-section-head i {
        color: #0f766e;
        font-size: 13px;
    }
    .pay-section-head .fc-req {
        margin-left: 2px;
    }

    /* ── Mode select (taller, primary) ──────────────────────────────── */
    .pay-mode-wrap {
        position: relative;
    }
    .pay-mode-select {
        width: 100%;
        padding: 11px 38px 11px 14px;
        font-size: 14px;
        font-weight: 600;
        color: #0f1f3a;
        background: #fff;
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        appearance: none;
        cursor: pointer;
        transition: border-color .15s, box-shadow .15s;
    }
    .pay-mode-select:focus {
        outline: none;
        border-color: #0f766e;
        box-shadow: 0 0 0 3px rgba(15,118,110,.12);
    }
    .pay-mode-select:invalid {
        color: #94a3b8;
        font-weight: 500;
    }

    /* ── Quick chips (Pay Full / Custom) ────────────────────────────── */
    .pay-quick-row {
        display: flex;
        gap: 8px;
        margin-bottom: 12px;
        flex-wrap: wrap;
    }
    .pay-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 14px;
        border-radius: 999px;
        border: 1.5px solid transparent;
        font-size: 12.5px;
        font-weight: 700;
        cursor: pointer;
        transition: transform .12s, box-shadow .15s;
    }
    .pay-chip i { font-size: 12px; }
    .pay-chip-primary {
        background: #0f766e;
        color: #fff;
    }
    .pay-chip-primary:hover {
        background: #115e59;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(15,118,110,.25);
    }
    .pay-chip-amber {
        background: #fff;
        color: #b45309;
        border-color: #fcd34d;
    }
    .pay-chip-amber:hover {
        background: #fef3c7;
        transform: translateY(-1px);
    }

    /* ── Amount inputs (3-up grid) ──────────────────────────────────── */
    .pay-amount-grid {
        display: grid;
        grid-template-columns: 1.2fr 1fr 1.5fr;
        gap: 12px;
    }
    @media (max-width: 720px) {
        .pay-amount-grid { grid-template-columns: 1fr; }
    }
    .pay-field {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    .pay-label {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #64748b;
    }
    .pay-input {
        padding: 10px 12px;
        font-size: 14px;
        font-weight: 500;
        color: #0f172a;
        background: #fff;
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        font-variant-numeric: tabular-nums;
        transition: border-color .15s, box-shadow .15s;
    }
    .pay-input:focus {
        outline: none;
        border-color: #0f766e;
        box-shadow: 0 0 0 3px rgba(15,118,110,.12);
    }
    .pay-input::placeholder { color: #cbd5e1; font-weight: 400; }
    .pay-input-primary {
        font-weight: 700;
        font-size: 16px;
        color: #0f1f3a;
        border-color: #0f766e;
        background: #f0fdfa;
    }
    .pay-input-primary:focus {
        background: #fff;
        box-shadow: 0 0 0 3px rgba(15,118,110,.18);
    }

    /* ── Calculation strip ──────────────────────────────────────────── */
    .pay-calc-strip {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px 16px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px 18px;
    }
    .pay-calc-item {
        display: flex;
        flex-direction: column;
        gap: 2px;
        min-width: 0;
    }
    .pay-calc-label {
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #94a3b8;
    }
    .pay-calc-val {
        font-family: 'Playfair Display', serif;
        font-size: 17px;
        font-weight: 700;
        color: #0f1f3a;
        font-variant-numeric: tabular-nums;
        line-height: 1.1;
    }
    .pay-calc-green { color: #16a34a; }
    .pay-calc-red   { color: #dc2626; }
    .pay-calc-blue  { color: #1d4ed8; }
    .pay-calc-op {
        font-size: 18px;
        font-weight: 700;
        color: #cbd5e1;
        align-self: center;
    }
    .pay-calc-item-strong {
        background: #fef2f2;
        border-radius: 8px;
        padding: 6px 12px;
        margin-left: auto;
    }
    .pay-calc-item-strong .pay-calc-label { color: #dc2626; }
    .pay-calc-item-strong .pay-calc-val   { font-size: 20px; }

    /* ── Allocation preview ─────────────────────────────────────────── */
    .pay-alloc {
        background: #f0fdfa;
        border: 1px solid #99f6e4;
        border-left: 4px solid #0f766e;
        border-radius: 10px;
        padding: 14px 16px;
    }
    .pay-alloc-title {
        display: flex;
        align-items: baseline;
        gap: 8px;
        margin-bottom: 10px;
        font-size: 12.5px;
        font-weight: 700;
        color: #0f766e;
        text-transform: uppercase;
        letter-spacing: .4px;
    }
    .pay-alloc-title i { font-size: 12px; }
    .pay-alloc-hint {
        font-size: 10.5px;
        font-weight: 500;
        color: #14b8a6;
        text-transform: none;
        letter-spacing: 0;
        margin-left: 2px;
    }

    /* ── Action bar ─────────────────────────────────────────────────── */
    .pay-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        flex-wrap: wrap;
        padding-top: 4px;
    }
    .pay-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 11px 22px;
        border-radius: 8px;
        border: 1.5px solid transparent;
        font-family: 'DM Sans', sans-serif;
        font-size: 13.5px;
        font-weight: 700;
        cursor: pointer;
        transition: transform .12s, box-shadow .15s, background .15s;
    }
    .pay-btn-ghost {
        background: #fff;
        color: #475569;
        border-color: #cbd5e1;
    }
    .pay-btn-ghost:hover {
        background: #f8fafc;
        color: #0f1f3a;
        border-color: #94a3b8;
    }
    .pay-btn-submit {
        background: linear-gradient(135deg, #0f766e 0%, #0d5a55 100%);
        color: #fff;
        box-shadow: 0 4px 12px rgba(15,118,110,.25);
    }
    .pay-btn-submit:hover {
        background: linear-gradient(135deg, #115e59 0%, #0a4744 100%);
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(15,118,110,.35);
    }
    .pay-btn-submit:active { transform: translateY(0); }
    .pay-btn-submit:disabled {
        background: #94a3b8;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    /* ══════════════════════════════════════════════════════════════════
       SUBMIT PAYMENT (Step 4) — REDESIGNED
       Scoped under .pay-card so it overrides ONLY Step 4. Other .fc-card
       sections (Breakdown, Tiles) keep their existing look.
       ══════════════════════════════════════════════════════════════════ */
    .pay-card {
        border: 1px solid #e2e8f0;
        background: #fff;
        box-shadow: 0 4px 18px rgba(15, 31, 58, .06);
        overflow: hidden;
    }

    /* ── Header strip ───────────────────────────────────────────────── */
    .pay-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 14px;
        padding: 14px 20px;
        background: linear-gradient(135deg, #0f1f3a 0%, #1a3358 100%);
        color: #fff;
    }
    .pay-head-left {
        display: flex;
        align-items: center;
        gap: 12px;
        min-width: 0;
    }
    .pay-step {
        width: 30px;
        height: 30px;
        border-radius: 8px;
        background: rgba(255,255,255,.10);
        color: #ffd479;
        font-size: 13px;
        font-weight: 700;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        flex-shrink: 0;
        font-family: 'DM Sans', sans-serif;
    }
    .pay-head h3 {
        font-family: 'Playfair Display', serif;
        font-size: 16px;
        font-weight: 700;
        color: #fff;
        margin: 0 0 1px;
        letter-spacing: .2px;
    }
    .pay-head-sub {
        font-size: 12px;
        margin: 0;
        color: rgba(255,255,255,.6);
        font-weight: 500;
    }
    .pay-head-due {
        display: flex;
        align-items: center;
        gap: 8px;
        background: rgba(255, 212, 121, .14);
        border: 1px solid rgba(255, 212, 121, .25);
        padding: 7px 14px;
        border-radius: 999px;
        flex-shrink: 0;
    }
    .pay-head-due-label {
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .6px;
        color: rgba(255,212,121,.85);
    }
    .pay-head-due-val {
        font-family: 'Playfair Display', serif;
        font-size: 16px;
        font-weight: 700;
        color: #ffd479;
        font-variant-numeric: tabular-nums;
    }

    /* ── Body ───────────────────────────────────────────────────────── */
    .pay-body {
        padding: 20px;
        display: flex;
        flex-direction: column;
        gap: 18px;
    }

    /* ── Section (groups: Mode / Amount) ────────────────────────────── */
    .pay-section {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px 16px;
    }
    .pay-section-head {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
        font-size: 12px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #0f1f3a;
    }
    .pay-section-head i {
        color: #0f766e;
        font-size: 13px;
    }
    .pay-section-head .fc-req {
        margin-left: 2px;
    }

    /* ── Mode select (taller, primary) ──────────────────────────────── */
    .pay-mode-wrap {
        position: relative;
    }
    .pay-mode-select {
        width: 100%;
        padding: 11px 38px 11px 14px;
        font-size: 14px;
        font-weight: 600;
        color: #0f1f3a;
        background: #fff;
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        appearance: none;
        cursor: pointer;
        transition: border-color .15s, box-shadow .15s;
    }
    .pay-mode-select:focus {
        outline: none;
        border-color: #0f766e;
        box-shadow: 0 0 0 3px rgba(15,118,110,.12);
    }
    .pay-mode-select:invalid {
        color: #94a3b8;
        font-weight: 500;
    }

    /* ── Quick chips (Pay Full / Custom) ────────────────────────────── */
    .pay-quick-row {
        display: flex;
        gap: 8px;
        margin-bottom: 12px;
        flex-wrap: wrap;
    }
    .pay-chip {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 7px 14px;
        border-radius: 999px;
        border: 1.5px solid transparent;
        font-size: 12.5px;
        font-weight: 700;
        cursor: pointer;
        transition: transform .12s, box-shadow .15s;
    }
    .pay-chip i { font-size: 12px; }
    .pay-chip-primary {
        background: #0f766e;
        color: #fff;
    }
    .pay-chip-primary:hover {
        background: #115e59;
        transform: translateY(-1px);
        box-shadow: 0 4px 10px rgba(15,118,110,.25);
    }
    .pay-chip-amber {
        background: #fff;
        color: #b45309;
        border-color: #fcd34d;
    }
    .pay-chip-amber:hover {
        background: #fef3c7;
        transform: translateY(-1px);
    }

    /* ── Amount inputs (3-up grid) ──────────────────────────────────── */
    .pay-amount-grid {
        display: grid;
        grid-template-columns: 1.2fr 1fr 1.5fr;
        gap: 12px;
    }
    @media (max-width: 720px) {
        .pay-amount-grid { grid-template-columns: 1fr; }
    }
    .pay-field {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }
    .pay-label {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #64748b;
    }
    .pay-input {
        padding: 10px 12px;
        font-size: 14px;
        font-weight: 500;
        color: #0f172a;
        background: #fff;
        border: 1.5px solid #cbd5e1;
        border-radius: 8px;
        font-variant-numeric: tabular-nums;
        transition: border-color .15s, box-shadow .15s;
    }
    .pay-input:focus {
        outline: none;
        border-color: #0f766e;
        box-shadow: 0 0 0 3px rgba(15,118,110,.12);
    }
    .pay-input::placeholder { color: #cbd5e1; font-weight: 400; }
    .pay-input-primary {
        font-weight: 700;
        font-size: 16px;
        color: #0f1f3a;
        border-color: #0f766e;
        background: #f0fdfa;
    }
    .pay-input-primary:focus {
        background: #fff;
        box-shadow: 0 0 0 3px rgba(15,118,110,.18);
    }

    /* ── Calculation strip ──────────────────────────────────────────── */
    .pay-calc-strip {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 8px 16px;
        background: #fff;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 14px 18px;
    }
    .pay-calc-item {
        display: flex;
        flex-direction: column;
        gap: 2px;
        min-width: 0;
    }
    .pay-calc-label {
        font-size: 10.5px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #94a3b8;
    }
    .pay-calc-val {
        font-family: 'Playfair Display', serif;
        font-size: 17px;
        font-weight: 700;
        color: #0f1f3a;
        font-variant-numeric: tabular-nums;
        line-height: 1.1;
    }
    .pay-calc-green { color: #16a34a; }
    .pay-calc-red   { color: #dc2626; }
    .pay-calc-op {
        font-size: 18px;
        font-weight: 700;
        color: #cbd5e1;
        align-self: center;
    }
    .pay-calc-item-strong {
        background: #fef2f2;
        border-radius: 8px;
        padding: 6px 12px;
        margin-left: auto;
    }
    .pay-calc-item-strong .pay-calc-label { color: #dc2626; }
    .pay-calc-item-strong .pay-calc-val   { font-size: 20px; }

    /* ── Allocation preview ─────────────────────────────────────────── */
    .pay-alloc {
        background: #f0fdfa;
        border: 1px solid #99f6e4;
        border-left: 4px solid #0f766e;
        border-radius: 10px;
        padding: 14px 16px;
    }
    .pay-alloc-title {
        display: flex;
        align-items: baseline;
        gap: 8px;
        margin-bottom: 10px;
        font-size: 12.5px;
        font-weight: 700;
        color: #0f766e;
        text-transform: uppercase;
        letter-spacing: .4px;
    }
    .pay-alloc-title i { font-size: 12px; }
    .pay-alloc-hint {
        font-size: 10.5px;
        font-weight: 500;
        color: #14b8a6;
        text-transform: none;
        letter-spacing: 0;
        margin-left: 2px;
    }

    /* ── Action bar ─────────────────────────────────────────────────── */
    .pay-actions {
        display: flex;
        gap: 10px;
        justify-content: flex-end;
        flex-wrap: wrap;
        padding-top: 4px;
    }
    .pay-btn {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        padding: 11px 22px;
        border-radius: 8px;
        border: 1.5px solid transparent;
        font-family: 'DM Sans', sans-serif;
        font-size: 13.5px;
        font-weight: 700;
        cursor: pointer;
        transition: transform .12s, box-shadow .15s, background .15s;
    }
    .pay-btn-ghost {
        background: #fff;
        color: #475569;
        border-color: #cbd5e1;
    }
    .pay-btn-ghost:hover {
        background: #f8fafc;
        color: #0f1f3a;
        border-color: #94a3b8;
    }
    .pay-btn-submit {
        background: linear-gradient(135deg, #0f766e 0%, #0d5a55 100%);
        color: #fff;
        box-shadow: 0 4px 12px rgba(15,118,110,.25);
    }
    .pay-btn-submit:hover {
        background: linear-gradient(135deg, #115e59 0%, #0a4744 100%);
        transform: translateY(-1px);
        box-shadow: 0 6px 16px rgba(15,118,110,.35);
    }
    .pay-btn-submit:active { transform: translateY(0); }
    .pay-btn-submit:disabled {
        background: #94a3b8;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    /* ══════════════════════════════════════════════════════════════════
       PAYMENT HISTORY MODAL — REDESIGNED
       Scoped under .hist-modal so it overrides ONLY this modal's defaults.
       Palette: dark navy header, soft teal accents, semantic full/partial
       pills, channel-coloured payment-mode pills.
       ══════════════════════════════════════════════════════════════════ */

    .hist-modal {
        max-width: 1080px;
        max-height: 92vh;
        overflow: hidden;            /* head sticks; body scrolls */
        display: flex;
        flex-direction: column;
        padding: 0;
        background: #fff;
    }

    /* ── HEADER ─────────────────────────────────────────────────────── */
    .hist-head {
        background: linear-gradient(135deg, #0f1f3a 0%, #1a3358 100%);
        color: #fff;
        padding: 16px 22px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 16px;
        border-radius: var(--fc-radius) var(--fc-radius) 0 0;
        flex-shrink: 0;
    }
    .hist-head-left {
        display: flex;
        align-items: center;
        gap: 14px;
        min-width: 0;
    }
    .hist-head-icon {
        width: 40px;
        height: 40px;
        border-radius: 10px;
        background: rgba(255,255,255,.10);
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 18px;
        color: #ffd479;          /* warm gold accent on cool navy */
        flex-shrink: 0;
    }
    .hist-head h4 {
        font-family: 'Playfair Display', serif;
        font-size: 18px;
        font-weight: 700;
        margin: 0 0 2px;
        color: #fff;
        letter-spacing: .2px;
    }
    .hist-head-sub {
        font-size: 12.5px;
        margin: 0;
        color: rgba(255,255,255,.65);
        font-weight: 500;
    }
    .hist-close {
        background: rgba(255,255,255,.08);
        border: 1px solid rgba(255,255,255,.12);
        color: rgba(255,255,255,.85);
        width: 34px;
        height: 34px;
        border-radius: 8px;
        cursor: pointer;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 14px;
        transition: background .15s, color .15s, transform .15s;
        flex-shrink: 0;
    }
    .hist-close:hover {
        background: rgba(255,255,255,.18);
        color: #fff;
    }
    .hist-close:active { transform: scale(.95); }

    /* ── BODY (scrolls) ─────────────────────────────────────────────── */
    .hist-body {
        padding: 18px 22px 22px;
        overflow: auto;
        flex: 1 1 auto;
    }

    /* ── KPI CHIPS ──────────────────────────────────────────────────── */
    .hist-kpis {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 12px;
        margin-bottom: 18px;
    }
    @media (max-width: 760px) {
        .hist-kpis { grid-template-columns: repeat(2, 1fr); }
    }
    .hist-kpi {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-left: 3px solid #94a3b8;
        border-radius: 10px;
        padding: 12px 14px;
        min-width: 0;
    }
    .hist-kpi-label {
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .5px;
        color: #64748b;
        margin-bottom: 4px;
    }
    .hist-kpi-val {
        font-size: 19px;
        font-weight: 700;
        color: #0f1f3a;
        line-height: 1.1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .hist-kpi-sub {
        font-size: 10.5px;
        color: #94a3b8;
        margin-top: 2px;
    }
    .hist-kpi-input { border-left-color: #2563eb; }
    .hist-kpi-input .hist-kpi-val { color: #1d4ed8; }
    .hist-kpi-alloc { border-left-color: #0f766e; }
    .hist-kpi-alloc .hist-kpi-val { color: #0f766e; }
    .hist-kpi-rem   { border-left-color: #dc2626; }
    .hist-kpi-rem   .hist-kpi-val { color: #dc2626; }

    /* ── TABLE ──────────────────────────────────────────────────────── */
    .hist-table-wrap {
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        overflow: auto;
        background: #fff;
    }
    .hist-table {
        width: 100%;
        border-collapse: separate;
        border-spacing: 0;
        font-size: 13px;
        color: #1e293b;
    }
    .hist-table thead th {
        position: sticky;
        top: 0;
        background: #f8fafc;
        color: #475569;
        font-size: 11px;
        font-weight: 700;
        text-transform: uppercase;
        letter-spacing: .6px;
        padding: 11px 14px;
        text-align: left;
        white-space: nowrap;
        border-bottom: 1px solid #e2e8f0;
        z-index: 1;
    }
    .hist-table th.th-num,
    .hist-table td.hist-num { text-align: right; white-space: nowrap; }
    .hist-table th.th-rcpt   { width: 90px; }
    .hist-table th.th-date   { width: 110px; }
    .hist-table th.th-status { width: 120px; }
    .hist-table th.th-mode   { width: 110px; }
    .hist-table th.th-num    { width: 105px; }

    .hist-table tbody td {
        padding: 11px 14px;
        border-bottom: 1px solid #f1f5f9;
        vertical-align: middle;
    }
    .hist-table tbody tr:last-child td { border-bottom: none; }
    .hist-table tbody tr:hover td { background: #f8fafc; }
    .hist-table tbody tr.hist-total:hover td { background: #eef2f7; }

    .hist-cell-date { color: #475569; font-variant-numeric: tabular-nums; }
    .hist-num       { font-variant-numeric: tabular-nums; }
    .hist-num-strong { font-weight: 600; color: #0f172a; }
    .hist-num-due    { color: #dc2626; font-weight: 600; }
    .hist-num-clear  { color: #94a3b8; }
    .hist-num-disc   { color: #16a34a; }
    .hist-dim        { color: #94a3b8; }
    .hist-num-tag {
        font-size: 10px;
        font-weight: 600;
        margin-top: 2px;
        line-height: 1.2;
    }
    .hist-num-tag.carry-tag { color: #0f766e; }

    /* ── PILLS ──────────────────────────────────────────────────────── */
    .hist-rcpt {
        display: inline-block;
        background: #eff6ff;
        color: #1d4ed8;
        font-weight: 700;
        font-size: 12px;
        padding: 3px 10px;
        border-radius: 14px;
        font-variant-numeric: tabular-nums;
    }

    .hist-pill {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 3px 10px;
        border-radius: 14px;
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .3px;
    }
    .hist-pill-full     { background: #dcfce7; color: #166534; }
    .hist-pill-partial  { background: #fef3c7; color: #92400e; }
    .hist-pill-refunded { background: #fee2e2; color: #991b1b; }
    .hist-pill-refund   { background: #fde2e2; color: #c2410c; }
    .hist-pill-unknown  { background: #f1f5f9; color: #94a3b8; padding: 3px 12px; }

    /* Refund / refunded row accents — red tint so reversed money is
       instantly visible and the viewer doesn't think F2 + F3 added up. */
    .hist-row-refund   td       { background: #fff7f5; }
    .hist-row-refunded td       { background: #fafafa; }
    .hist-rcpt-refund           { color: #b91c1c; font-weight: 700; }
    .hist-rcpt-struck           { text-decoration: line-through; color: #94a3b8; }
    .hist-num-refund            { color: #b91c1c; font-weight: 600; }
    .hist-kpi-sub               { font-size: 11px; color: #94a3b8; margin-top: 2px; font-weight: 500; }

    .mode-pill {
        display: inline-block;
        padding: 3px 10px;
        border-radius: 14px;
        font-size: 11px;
        font-weight: 600;
        letter-spacing: .2px;
        white-space: nowrap;
    }
    .mode-cash    { background: #ecfdf5; color: #047857; }
    .mode-bank    { background: #eff6ff; color: #1d4ed8; }
    .mode-online  { background: #f3e8ff; color: #6d28d9; }
    .mode-other   { background: #f1f5f9; color: #475569; }

    .hist-month-chip {
        display: inline-block;
        background: #eef2ff;
        color: #3730a3;
        padding: 2px 9px;
        border-radius: 12px;
        font-size: 11px;
        font-weight: 600;
        margin: 1px 3px 1px 0;
    }
    .hist-month-chip.yearly {
        background: #ccfbf1;
        color: #0f766e;
    }

    /* ── TOTAL ROW ──────────────────────────────────────────────────── */
    .hist-table tbody tr.hist-total td {
        background: #eef2f7;
        border-top: 2px solid #cbd5e1;
        border-bottom: none;
        color: #0f1f3a;
        font-size: 13px;
        padding: 13px 14px;
    }

    /* ── EMPTY STATE ────────────────────────────────────────────────── */
    .hist-empty {
        text-align: center;
        padding: 48px 20px !important;
        color: #64748b;
        background: #fff !important;
    }
    .hist-empty-icon {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: #f1f5f9;
        color: #94a3b8;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 22px;
        margin-bottom: 14px;
    }
    .hist-empty-title {
        font-size: 15px;
        font-weight: 600;
        color: #334155;
        margin-bottom: 4px;
    }
    .hist-empty-sub {
        font-size: 12.5px;
        color: #94a3b8;
    }
    .hist-empty-error .hist-empty-icon {
        background: #fee2e2;
        color: #dc2626;
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