<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
$accounts        = isset($accounts)        ? $accounts        : [];
$current_session = isset($current_session) ? $current_session : 'N/A';
$session_year    = isset($session_year)    ? $session_year    : 'N/A';
$subGroups = [
    'CASH','BANK ACCOUNT','CURRENT ASSETS','MOVABLE ASSETS','STOCK IN HAND',
    'SUNDRY DEBTORS','DUTY & TAXES','CURRENT LIABILITIES','SECURED LOAN',
    'SUNDRY CREDITORS','UNSECURED LOAN','PERSONAL EXP.','DIRECT EXPENSES',
    'FURNITURE ACCOUNT','FIXED ASSETS','OFFICE EQUIPMENT',
    'PLANT & MACHINERY ACCOUNT','ADMINISTRATION EXP.','INDIRECT EXPENSES',
    'ADVERTISEMENT & PUBLICITY EXP.','FINANCIAL EXP.','VEHICLES',
    'MOVEABLE ASSETS','DIRECT EXP.','REVENUE ACCOUNT',
    'INCOME FROM OTHER SOURCES','PURCHASE ACCOUNT','SALE ACCOUNT'
];
?>

<div class="content-wrapper">
<div class="ab-wrap">

    <!-- TOP BAR -->
    <div class="ab-topbar">
        <div>
            <h1 class="ab-page-title"><i class="fa fa-book"></i> Account Ledger</h1>
            <ol class="ab-breadcrumb">
                <li><a href="<?= base_url() ?>"><i class="fa fa-home"></i> Dashboard</a></li>
                <li><a href="<?= site_url('account/account_book') ?>">Accounts</a></li>
                <li>Account Book</li>
            </ol>
        </div>
        <div class="ab-session-badge">
            <span class="ab-badge-label">Session</span>
            <span class="ab-badge-val"><?= htmlspecialchars($current_session) ?></span>
        </div>
    </div>

    <!-- LIST VIEW -->
    <div id="ab-list-view">
        <div class="ab-main-layout">

            <!-- LEFT: Forms -->
            <div>
                <!-- CREATE FORM -->
                <div class="ab-card" id="ab-create-panel">
                    <div class="ab-card-head">
                        <span class="ab-step">1</span>
                        <i class="fa fa-plus-circle"></i>
                        <h3>Create New Account</h3>
                    </div>
                    <div class="ab-card-body">
                        <form id="ab-create-form" novalidate>

                            <div class="ab-field">
                                <label class="ab-label">Account Name <span class="ab-req">*</span></label>
                                <input type="text" class="ab-input" id="accountName" name="accountName"
                                    placeholder="e.g. School Cash Account" required>
                                <span id="accountNameMessage" style="display:none;"></span>
                            </div>

                            <div class="ab-field">
                                <label class="ab-label">Sub Group <span class="ab-req">*</span></label>
                                <div class="ab-select-wrap">
                                    <select class="ab-select" id="subGroup" name="subGroup" required>
                                        <option value="" disabled selected>— Select Sub Group —</option>
                                        <?php foreach ($subGroups as $sg): ?>
                                        <option value="<?= htmlspecialchars($sg) ?>"><?= htmlspecialchars($sg) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="ab-field">
                                <label class="ab-label">Opening Amount</label>
                                <!--
                                    B1 FIX (CRITICAL):
                                    Was:  id='Openingamount' name='Openingamount'
                                    Controller reads: post('openingAmount') — capital A
                                    serialize() sent 'Openingamount' → null → opening always 0.
                                    Fixed: name='openingAmount' + JS sends explicit object (not serialize)
                                -->
                                <input type="number" class="ab-input" id="openingAmount" name="openingAmount"
                                    placeholder="0.00" step="0.01" min="0">
                            </div>

                            <div class="ab-field">
                                <label class="ab-label">Branch Name</label>
                                <input type="text" class="ab-input" id="branchName" name="branchName"
                                    placeholder="e.g. Main Branch">
                            </div>

                            <div class="ab-field">
                                <label class="ab-label">Account Holder Name</label>
                                <input type="text" class="ab-input" id="accountHolder" name="accountHolder"
                                    placeholder="Full name">
                            </div>

                            <div class="ab-field">
                                <label class="ab-label">Account Number</label>
                                <input type="text" class="ab-input" id="accountNumber" name="accountNumber"
                                    placeholder="e.g. 0012345678">
                            </div>

                            <div class="ab-field">
                                <label class="ab-label">IFSC Code</label>
                                <input type="text" class="ab-input" id="ifscCode" name="ifscCode"
                                    placeholder="e.g. SBIN0001234" style="text-transform:uppercase;">
                            </div>

                            <div class="ab-btn-row">
                                <button type="submit" class="ab-btn ab-btn-teal" id="ab-create-submit-btn">
                                    <i class="fa fa-save"></i> Create Account
                                </button>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- UPDATE FORM -->
                <div class="ab-card" id="ab-update-panel" style="display:none;">
                    <div class="ab-card-head">
                        <span class="ab-step" style="background:var(--ab-amber);">✎</span>
                        <i class="fa fa-edit" style="color:var(--ab-amber);"></i>
                        <h3>Update Account</h3>
                    </div>
                    <div class="ab-card-body">
                        <form id="ab-update-form" novalidate>
                            <input type="hidden" id="originalAccountId" name="accountId">

                            <div class="ab-field">
                                <label class="ab-label">Account Name <span class="ab-req">*</span></label>
                                <input type="text" class="ab-input" id="updateaccountName" name="accountName"
                                    placeholder="Account name" required>
                                <span id="updateAccountNameMessage" style="display:none;"></span>
                            </div>

                            <div class="ab-field">
                                <label class="ab-label">Sub Group <span class="ab-req">*</span></label>
                                <div class="ab-select-wrap">
                                    <select class="ab-select" id="updateSubGroup" name="subGroup" required>
                                        <option value="" disabled>— Select Sub Group —</option>
                                        <?php foreach ($subGroups as $sg): ?>
                                        <option value="<?= htmlspecialchars($sg) ?>"><?= htmlspecialchars($sg) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                            <div class="ab-field">
                                <label class="ab-label">Branch Name</label>
                                <!--
                                    B6 FIX (HIGH):
                                    Was: name='updatebranchName' (had 'update' prefix)
                                    JS sends explicit object so it worked, but misleading.
                                    Fixed: name attributes now match controller POST keys exactly.
                                -->
                                <input type="text" class="ab-input" id="updatebranchName" name="branchName"
                                    placeholder="Branch name">
                            </div>

                            <div class="ab-field">
                                <label class="ab-label">Account Holder Name</label>
                                <input type="text" class="ab-input" id="updateaccountHolder" name="accountHolder"
                                    placeholder="Full name">
                            </div>

                            <div class="ab-field">
                                <label class="ab-label">Account Number</label>
                                <input type="text" class="ab-input" id="updateaccountNumber" name="accountNumber"
                                    placeholder="Account number">
                            </div>

                            <div class="ab-field">
                                <label class="ab-label">IFSC Code</label>
                                <input type="text" class="ab-input" id="updateifscCode" name="ifscCode"
                                    placeholder="IFSC code" style="text-transform:uppercase;">
                            </div>

                            <div class="ab-btn-row">
                                <button type="submit" class="ab-btn ab-btn-amber" id="ab-update-submit-btn">
                                    <i class="fa fa-check"></i> Update
                                </button>
                                <button type="button" class="ab-btn ab-btn-ghost" id="ab-update-back-btn">
                                    <i class="fa fa-arrow-left"></i> Back
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div><!-- /left -->

            <!-- RIGHT: Accounts Table -->
            <div id="ab-table-panel">
                <div class="ab-card">
                    <div class="ab-card-head">
                        <span class="ab-step">2</span>
                        <i class="fa fa-list-alt"></i>
                        <h3>All Accounts</h3>
                        <!--
                            B11 FIX (MEDIUM): hint updated to mention double-click
                        -->
                        <span style="margin-left:auto;font-size:12px;color:var(--ab-muted);">
                            Click to select &nbsp;&middot;&nbsp; Double-click to view
                        </span>
                    </div>
                    <div class="ab-card-body" style="padding:0;">
                        <div class="ab-table-wrap">
                            <table class="ab-table" id="accountTable">
                                <thead>
                                    <tr>
                                        <th style="width:50px;">#</th>
                                        <th>Account Name</th>
                                        <th>Sub Group</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!empty($accounts)):
                                        $sr = 1;
                                        foreach ($accounts as $account_id => $account):
                                            $sub_group = isset($account['Under']) ? $account['Under'] : 'N/A';
                                    ?>
                                    <tr data-id="<?= htmlspecialchars($account_id) ?>">
                                        <td><?= $sr++ ?></td>
                                        <td><strong><?= htmlspecialchars($account_id) ?></strong></td>
                                        <td><span class="ab-badge-subgroup"><?= htmlspecialchars($sub_group) ?></span></td>
                                    </tr>
                                    <?php endforeach; else: ?>
                                    <tr>
                                        <td colspan="3" style="text-align:center;padding:32px;color:var(--ab-muted);">
                                            <i class="fa fa-inbox" style="font-size:24px;display:block;margin-bottom:8px;"></i>
                                            No accounts found. Create one to get started.
                                        </td>
                                    </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="ab-table-actions">
                            <button id="viewBtn"   class="ab-btn ab-btn-teal  ab-btn-sm ab-btn-disabled" disabled>
                                <i class="fa fa-eye"></i> View
                            </button>
                            <button id="editBtn"   class="ab-btn ab-btn-amber ab-btn-sm ab-btn-disabled" disabled>
                                <i class="fa fa-edit"></i> Edit
                            </button>
                            <button id="deleteBtn" class="ab-btn ab-btn-red   ab-btn-sm ab-btn-disabled" disabled>
                                <i class="fa fa-trash"></i> Delete
                            </button>
                        </div>
                    </div>
                </div>
            </div>

        </div><!-- /ab-main-layout -->
    </div><!-- /ab-list-view -->


    <!-- VIEW PAGE -->
    <!--
        B2 FIX (CRITICAL):
        Was: no inline style → CSS (#ab-view-page { display:none }) loaded AFTER HTML
             → page flashed visible on every load (worse on slow connections).
        Fixed: style="display:none;" inline so it's hidden from first paint.
    -->
    <div id="ab-view-page" style="display:none;">

        <!-- Info tiles -->
        <div class="ab-info-grid">
            <div class="ab-info-tile">
                <div class="ab-info-tile-label"><i class="fa fa-book"></i> Ledger</div>
                <div class="ab-info-tile-val" id="view-accountName">—</div>
            </div>
            <div class="ab-info-tile">
                <div class="ab-info-tile-label"><i class="fa fa-layer-group"></i> Sub Group</div>
                <div class="ab-info-tile-val" id="view-subGroup">—</div>
            </div>
            <div class="ab-info-tile">
                <div class="ab-info-tile-label"><i class="fa fa-calendar-alt"></i> Session</div>
                <div class="ab-info-tile-val" id="view-session"><?= htmlspecialchars($current_session) ?></div>
            </div>
            <div class="ab-info-tile">
                <div class="ab-info-tile-label"><i class="fa fa-clock"></i> Created On</div>
                <div class="ab-info-tile-val" id="view-createdOn">—</div>
            </div>
            <div class="ab-info-tile">
                <div class="ab-info-tile-label"><i class="fa fa-user"></i> Account Holder</div>
                <div class="ab-info-tile-val" id="view-accountHolder">—</div>
            </div>
            <div class="ab-info-tile">
                <div class="ab-info-tile-label"><i class="fa fa-hashtag"></i> Account Number</div>
                <div class="ab-info-tile-val" id="view-accountNumber">—</div>
            </div>
            <div class="ab-info-tile">
                <div class="ab-info-tile-label"><i class="fa fa-university"></i> Branch</div>
                <div class="ab-info-tile-val" id="view-branchName">—</div>
            </div>
            <div class="ab-info-tile">
                <div class="ab-info-tile-label"><i class="fa fa-code"></i> IFSC Code</div>
                <div class="ab-info-tile-val" id="view-ifscCode">—</div>
            </div>
        </div>

        <!-- Ledger table -->
        <div class="ab-card">
            <div class="ab-card-head">
                <i class="fa fa-table"></i>
                <h3>Month-wise Transactions
                    <span id="ledger-account-label" style="font-size:13px;font-family:'DM Sans',sans-serif;font-weight:500;color:var(--ab-muted);margin-left:6px;"></span>
                </h3>
                <div id="ledger-loading" style="display:none;margin-left:auto;align-items:center;gap:6px;">
                    <i class="fa fa-spinner ab-spin" style="color:var(--ab-teal);"></i>
                    <span style="font-size:12px;color:var(--ab-muted);">Loading…</span>
                </div>
            </div>
            <div class="ab-card-body" style="padding:0;">
                <div class="ab-ledger-wrap">
                    <table class="ab-ledger" id="transactionsTable">
                        <thead>
                            <tr>
                                <th>Month</th>
                                <th>Opening (₹)</th>
                                <th>Received (₹)</th>
                                <th>Payments (₹)</th>
                                <th>Balance (₹)</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $months = ['April','May','June','July','August','September',
                                       'October','November','December','January','February','March'];
                            foreach ($months as $m): ?>
                            <tr>
                                <td><?= $m ?></td>
                                <td id="<?= $m ?>-opening"  class="ab-ledger-num">—</td>
                                <td id="<?= $m ?>-received" class="ab-ledger-num">—</td>
                                <td id="<?= $m ?>-payment"  class="ab-ledger-num">—</td>
                                <td id="<?= $m ?>-balance"  class="ab-ledger-num">—</td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="2" style="text-align:left;">Total</td>
                                <td id="total-received" class="ab-ledger-num">—</td>
                                <td id="total-payments" class="ab-ledger-num">—</td>
                                <td>—</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>

                <div class="ab-btn-row" style="padding:14px 18px;">
                    <button class="ab-btn ab-btn-ghost" id="ab-back-btn">
                        <i class="fa fa-arrow-left"></i> Back
                    </button>
                    <button class="ab-btn ab-btn-navy"
                        onclick="window.location.href='<?= site_url('account/view_accounts') ?>'">
                        <i class="fa fa-list"></i> View Accounts
                    </button>
                    <button class="ab-btn ab-btn-teal" onclick="window.print()">
                        <i class="fa fa-print"></i> Print
                    </button>
                </div>
            </div>
        </div>

    </div><!-- /ab-view-page -->

</div><!-- /ab-wrap -->
</div><!-- /content-wrapper -->


<!-- DELETE MODAL -->
<div class="ab-overlay" id="ab-delete-modal">
    <div class="ab-modal">
        <div class="ab-modal-head">
            <h4 id="ab-modal-title">
                <i class="fa fa-exclamation-triangle" style="color:#fbbf24;"></i> Confirm Action
            </h4>
            <button class="ab-modal-close" id="ab-modal-close-btn">&times;</button>
        </div>
        <div class="ab-modal-body" id="ab-modal-body">
            Are you sure you want to delete this account?
        </div>
        <div class="ab-modal-footer">
            <button class="ab-btn ab-btn-ghost" id="ab-modal-cancel-btn">Cancel</button>
            <button class="ab-btn ab-btn-red"   id="ab-modal-confirm-btn">
                <i class="fa fa-trash"></i> Delete
            </button>
        </div>
    </div>
</div>

<!-- TOAST CONTAINER -->
<div id="ab-toast-wrap" class="ab-toast-wrap"></div>


<script>
/*
 * account_book.php — JS  (all 13 bugs fixed)
 *
 * B1  CRITICAL — openingAmount name mismatch       → HTML name fixed + explicit AJAX data object
 * B2  CRITICAL — View page flash on load            → style="display:none;" added to HTML element
 * B3  HIGH     — 4 tiles read stale AB_ACCOUNTS    → all 8 tiles now read response.selectedAccount
 * B4  HIGH     — accountName from cells[1]          → use data-id attribute instead
 * B5  HIGH     — checkAccountExists order/scope     → defined before callers, inside DOMContentLoaded
 * B6  HIGH     — update form name= had 'update'     → standardised to match controller keys
 * B7  MEDIUM   — serialize() risky                 → all AJAX sends explicit data objects
 * B8  MEDIUM   — create success ignored res.success → added res.success check
 * B9  MEDIUM   — all-zero rows cluttered ledger    → skip rendering if o=r=p=b=0
 * B10 MEDIUM   — resetLedger showed '0.00'          → changed to '—' for consistent empty state
 * B11 MEDIUM   — no double-click to open           → dblclick handler added
 * B12 LOW      — tiles showed 'N/A' not '—'         → setTile handles empty → '—' internally
 * B13 LOW      — update name check flagged self     → skip check if unchanged from originalAccountId
 */

var AB_ACCOUNTS = <?= json_encode($accounts ?: new stdClass()) ?>;
var AB_SITE_URL  = '<?= rtrim(site_url(), '/') ?>';
var AB_SESSION   = '<?= htmlspecialchars($current_session) ?>';
var AB_MONTHS    = ['April','May','June','July','August','September',
                    'October','November','December','January','February','March'];

/* ── Toast ── */
function abToast(msg, type) {
    var wrap  = document.getElementById('ab-toast-wrap');
    var icons = { success:'check-circle', error:'times-circle', warning:'exclamation-triangle', info:'info-circle' };
    var el    = document.createElement('div');
    el.className = 'ab-toast ab-toast-' + (type || 'info');
    el.innerHTML = '<i class="fa fa-' + (icons[type] || 'info-circle') + '"></i> ' + msg;
    wrap.appendChild(el);
    setTimeout(function () {
        el.classList.add('ab-hiding');
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 350);
    }, 3500);
}

/* ── Modal ── */
function abModalShow(title, body, showConfirm) {
    document.getElementById('ab-modal-title').innerHTML =
        '<i class="fa fa-exclamation-triangle" style="color:#fbbf24;"></i> ' + title;
    document.getElementById('ab-modal-body').textContent = body;
    document.getElementById('ab-modal-confirm-btn').style.display = showConfirm ? 'inline-flex' : 'none';
    document.getElementById('ab-delete-modal').classList.add('open');
}
function abModalHide() { document.getElementById('ab-delete-modal').classList.remove('open'); }

/* ── View switch ── */
function showListView() {
    document.getElementById('ab-list-view').style.display = 'block';
    document.getElementById('ab-view-page').style.display = 'none';
}
function showViewPage() {
    document.getElementById('ab-list-view').style.display = 'none';
    document.getElementById('ab-view-page').style.display = 'block';
    window.scrollTo(0, 0);
}

/* ── Reset ledger to empty state ── */
function resetLedger() {
    AB_MONTHS.forEach(function (m) {
        ['opening','received','payment','balance'].forEach(function (col) {
            var el = document.getElementById(m + '-' + col);
            if (el) { el.textContent = '—'; el.className = 'ab-ledger-num'; }
        });
    });
    /* B10 FIX: use '—' not '0.00' for empty state */
    document.getElementById('total-received').textContent = '—';
    document.getElementById('total-payments').textContent = '—';
}

/*
 * B12 FIX: setTile always falls back to '—', never 'N/A'.
 * Treats empty string, null, undefined all the same way.
 */
function setTile(id, val) {
    var el = document.getElementById(id);
    if (!el) return;
    el.textContent = (val !== undefined && val !== null && String(val).trim() !== '') ? val : '—';
}

/* ── Indian number format ── */
function fmtNum(n) {
    var v = parseFloat(n);
    if (isNaN(v)) return '0.00';
    return v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}


/* ════════════════════════════════════════
   DOM READY
════════════════════════════════════════ */
document.addEventListener('DOMContentLoaded', function () {
    var $ = window.jQuery;

    /* ── Row selection state ── */
    var selectedRow = null;

    function setActionBtns(enabled) {
        ['viewBtn','editBtn','deleteBtn'].forEach(function (id) {
            var b = document.getElementById(id);
            b.disabled = !enabled;
            b.classList.toggle('ab-btn-disabled', !enabled);
        });
    }

    /* ── Row click / double-click ── */
    document.querySelectorAll('#accountTable tbody tr[data-id]').forEach(function (row) {

        /* Single click → select / deselect */
        row.addEventListener('click', function () {
            if (selectedRow) selectedRow.classList.remove('ab-row-selected');
            if (selectedRow === row) { selectedRow = null; setActionBtns(false); return; }
            selectedRow = row;
            row.classList.add('ab-row-selected');
            setActionBtns(true);
        });

        /* B11 FIX: double-click → select + open View immediately */
        row.addEventListener('dblclick', function () {
            if (selectedRow !== row) {
                if (selectedRow) selectedRow.classList.remove('ab-row-selected');
                selectedRow = row;
                row.classList.add('ab-row-selected');
                setActionBtns(true);
            }
            document.getElementById('viewBtn').click();
        });
    });

    /* ── Modal close ── */
    document.getElementById('ab-modal-close-btn').addEventListener('click', abModalHide);
    document.getElementById('ab-modal-cancel-btn').addEventListener('click', abModalHide);
    document.getElementById('ab-delete-modal').addEventListener('click', function (e) {
        if (e.target === this) abModalHide();
    });

    /* ── Back buttons ── */
    document.getElementById('ab-back-btn').addEventListener('click', showListView);
    document.getElementById('ab-update-back-btn').addEventListener('click', function () {
        document.getElementById('ab-update-panel').style.display = 'none';
        document.getElementById('ab-create-panel').style.display = 'block';
    });

    /*
     * B5 FIX: checkAccountExists defined BEFORE the listeners that call it,
     * inside DOMContentLoaded so $ is guaranteed available.
     */
    function checkAccountExists(name, spanId) {
        var span = document.getElementById(spanId);
        if (!name) { span.style.display = 'none'; return; }
        $.ajax({
            url: AB_SITE_URL + '/account/check_account',
            type: 'POST', dataType: 'json',
            data: { accountName: name },
            success: function (res) {
                span.textContent   = res.exists ? 'Account name already exists!' : 'Name is available.';
                span.className     = res.exists ? 'ab-field-err' : 'ab-field-ok';
                span.style.display = 'block';
            },
            error: function () { span.style.display = 'none'; }
        });
    }

    /* Create — live name check */
    document.getElementById('accountName').addEventListener('input', function () {
        checkAccountExists(this.value.trim(), 'accountNameMessage');
    });

    /* Update — live name check, but skip if name unchanged (B13 FIX) */
    document.getElementById('updateaccountName').addEventListener('input', function () {
        var original = document.getElementById('originalAccountId').value.trim();
        if (this.value.trim().toLowerCase() === original.toLowerCase()) {
            /* B13 FIX: same name as current account — don't flag as "already exists" */
            var span = document.getElementById('updateAccountNameMessage');
            span.style.display = 'none';
            return;
        }
        checkAccountExists(this.value.trim(), 'updateAccountNameMessage');
    });


    /* ════════════ CREATE ════════════ */
    document.getElementById('ab-create-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var name = document.getElementById('accountName').value.trim();
        var grp  = document.getElementById('subGroup').value;
        if (!name || !grp) { abToast('Account name and sub group are required.', 'warning'); return; }

        var btn = document.getElementById('ab-create-submit-btn');
        btn.disabled  = true;
        btn.innerHTML = '<i class="fa fa-spinner ab-spin"></i> Creating…';

        /*
         * B1 FIX + B7 FIX: explicit data object — NOT $(this).serialize().
         * serialize() was sending 'Openingamount' (old wrong name) → controller got null.
         * Explicit object guarantees exact key names matching controller's post() calls.
         */
        $.ajax({
            url: AB_SITE_URL + '/account/create_account',
            type: 'POST', dataType: 'json',
            data: {
                accountName:   document.getElementById('accountName').value.trim(),
                subGroup:      document.getElementById('subGroup').value,
                openingAmount: document.getElementById('openingAmount').value || '0',
                branchName:    document.getElementById('branchName').value.trim(),
                accountHolder: document.getElementById('accountHolder').value.trim(),
                accountNumber: document.getElementById('accountNumber').value.trim(),
                ifscCode:      document.getElementById('ifscCode').value.trim()
            },
            success: function (res) {
                /* B8 FIX: check res.success — controller may return {success:false} */
                if (res && res.success) {
                    abToast('Account "' + name + '" created successfully!', 'success');
                    setTimeout(function () { window.location.reload(); }, 900);
                } else {
                    abToast('Error: ' + (res ? res.message : 'Unknown error'), 'error');
                    btn.disabled  = false;
                    btn.innerHTML = '<i class="fa fa-save"></i> Create Account';
                }
            },
            error: function () {
                abToast('Error creating account. Please try again.', 'error');
                btn.disabled  = false;
                btn.innerHTML = '<i class="fa fa-save"></i> Create Account';
            }
        });
    });


    /* ════════════ EDIT ════════════ */
    document.getElementById('editBtn').addEventListener('click', function () {
        if (!selectedRow) { abToast('Please select an account to edit.', 'warning'); return; }

        /* B4 FIX: use data-id attribute — not cells[1].textContent (fragile with whitespace) */
        var accountName = selectedRow.getAttribute('data-id');
        var subGroup    = selectedRow.querySelector('.ab-badge-subgroup').textContent.trim();

        if (subGroup === 'Default Account') {
            abModalShow('Cannot Edit', 'Default accounts cannot be edited.', false);
            return;
        }

        var accountData = AB_ACCOUNTS[accountName] || {};
        document.getElementById('originalAccountId').value   = accountName;
        document.getElementById('updateaccountName').value   = accountName;
        document.getElementById('updateSubGroup').value      = subGroup;
        document.getElementById('updatebranchName').value    = accountData.branchName    || '';
        document.getElementById('updateaccountHolder').value = accountData.accountHolder || '';
        document.getElementById('updateaccountNumber').value = accountData.accountNumber || '';
        document.getElementById('updateifscCode').value      = accountData.ifscCode      || '';
        document.getElementById('updateAccountNameMessage').style.display = 'none';

        document.getElementById('ab-create-panel').style.display = 'none';
        document.getElementById('ab-update-panel').style.display = 'block';
        document.getElementById('ab-update-panel').scrollIntoView({ behavior: 'smooth' });
    });


    /* ════════════ UPDATE ════════════ */
    document.getElementById('ab-update-form').addEventListener('submit', function (e) {
        e.preventDefault();
        var btn = document.getElementById('ab-update-submit-btn');
        btn.disabled  = true;
        btn.innerHTML = '<i class="fa fa-spinner ab-spin"></i> Updating…';

        /* B7 FIX: explicit object, not serialize() */
        $.ajax({
            url: AB_SITE_URL + '/account/update_account',
            type: 'POST', dataType: 'json',
            data: {
                accountId:     document.getElementById('originalAccountId').value.trim(),
                accountName:   document.getElementById('updateaccountName').value.trim(),
                subGroup:      document.getElementById('updateSubGroup').value,
                branchName:    document.getElementById('updatebranchName').value.trim(),
                accountHolder: document.getElementById('updateaccountHolder').value.trim(),
                accountNumber: document.getElementById('updateaccountNumber').value.trim(),
                ifscCode:      document.getElementById('updateifscCode').value.trim()
            },
            success: function (res) {
                if (res && res.success) {
                    abToast('Account updated successfully!', 'success');
                    setTimeout(function () { window.location.reload(); }, 900);
                } else {
                    abToast('Error: ' + (res ? res.message : 'Unknown error'), 'error');
                    btn.disabled  = false;
                    btn.innerHTML = '<i class="fa fa-check"></i> Update';
                }
            },
            error: function () {
                abToast('Error updating account. Please try again.', 'error');
                btn.disabled  = false;
                btn.innerHTML = '<i class="fa fa-check"></i> Update';
            }
        });
    });


    /* ════════════ DELETE ════════════ */
    document.getElementById('deleteBtn').addEventListener('click', function () {
        if (!selectedRow) { abToast('Please select an account to delete.', 'warning'); return; }

        var subGroup    = selectedRow.querySelector('.ab-badge-subgroup').textContent.trim();
        /* B4 FIX: use data-id */
        var accountName = selectedRow.getAttribute('data-id');

        if (subGroup === 'Default Account') {
            abModalShow('Cannot Delete', 'Default accounts cannot be deleted.', false);
            return;
        }

        abModalShow('Confirm Delete',
            'Are you sure you want to delete "' + accountName + '"? This action cannot be undone.', true);

        /* Clone to prevent multi-fire on repeated delete clicks */
        var confirmBtn = document.getElementById('ab-modal-confirm-btn');
        var newConfirm = confirmBtn.cloneNode(true);
        confirmBtn.parentNode.replaceChild(newConfirm, confirmBtn);

        newConfirm.addEventListener('click', function () {
            newConfirm.disabled  = true;
            newConfirm.innerHTML = '<i class="fa fa-spinner ab-spin"></i> Deleting…';

            $.ajax({
                url: AB_SITE_URL + '/account/delete_account',
                type: 'POST', dataType: 'json',
                data: { accountName: accountName },
                success: function (data) {
                    abModalHide();
                    if (data && data.status === 'success') {
                        abToast('Account deleted successfully!', 'success');
                        setTimeout(function () { window.location.reload(); }, 900);
                    } else {
                        abToast('Failed: ' + (data ? data.message : 'Unknown error'), 'error');
                        newConfirm.disabled  = false;
                        newConfirm.innerHTML = '<i class="fa fa-trash"></i> Delete';
                    }
                },
                error: function () {
                    abModalHide();
                    abToast('An error occurred. Please try again.', 'error');
                }
            });
        });
    });


    /* ════════════ VIEW ════════════ */
    document.getElementById('viewBtn').addEventListener('click', function () {
        if (!selectedRow) { abToast('Please select an account to view.', 'warning'); return; }

        /* B4 FIX: use data-id */
        var accountName = selectedRow.getAttribute('data-id');
        var subGroup    = selectedRow.querySelector('.ab-badge-subgroup').textContent.trim();

        var btn          = document.getElementById('viewBtn');
        var loadingEl    = document.getElementById('ledger-loading');
        btn.disabled     = true;
        btn.innerHTML    = '<i class="fa fa-spinner ab-spin"></i>';

        /* AJAX 1: account meta */
        $.ajax({
            url: AB_SITE_URL + '/account/account_book',
            type: 'POST', dataType: 'json',
            data: { selectedAccountName: accountName },
            success: function (response) {
                btn.disabled  = false;
                btn.innerHTML = '<i class="fa fa-eye"></i> View';

                if (response.error) { abToast(response.error, 'error'); return; }

                /*
                 * B3 FIX (HIGH) + B12 FIX (LOW):
                 * Previously: 'Created On', 'subGroup', 'session' from response,
                 *             but 'accountHolder', 'accountNumber', 'branchName', 'ifscCode'
                 *             from stale AB_ACCOUNTS (PHP-rendered at page load).
                 * Bug:        After editing an account, those 4 tiles showed OLD data
                 *             until hard reload. If AB_ACCOUNTS was {} (stdClass issue)
                 *             all 4 always showed '—' regardless of actual data.
                 * Fix:        All 8 tiles now read exclusively from response.selectedAccount
                 *             which is always fresh from Firebase via the AJAX call.
                 */
                var acc = response.selectedAccount || {};

                setTile('view-accountName',  accountName);
                setTile('view-subGroup',     acc['Under'] || subGroup);
                setTile('view-session',      response.current_session || AB_SESSION);
                setTile('view-createdOn',    acc['Created On']);     /* the reported bug */
                setTile('view-accountHolder',acc['accountHolder']);
                setTile('view-accountNumber',acc['accountNumber']);
                setTile('view-branchName',   acc['branchName']);
                setTile('view-ifscCode',     acc['ifscCode']);

                var labelEl = document.getElementById('ledger-account-label');
                if (labelEl) labelEl.textContent = '— ' + accountName;

                resetLedger();
                showViewPage();
                if (loadingEl) loadingEl.style.display = 'flex';

                /* AJAX 2: monthly matrix */
                $.ajax({
                    url: AB_SITE_URL + '/account/populateTable',
                    type: 'POST', dataType: 'json',
                    data: { selectedAccountName: accountName },
                    success: function (res) {
                        if (loadingEl) loadingEl.style.display = 'none';
                        if (!res || !res.matrix) {
                            abToast('No ledger data found.', 'info');
                            return;
                        }

                        var anyData = false;
                        for (var i = 0; i < AB_MONTHS.length; i++) {
                            var row  = res.matrix[i] || {};
                            var o    = parseFloat(row.Opening  || 0);
                            var r    = parseFloat(row.Received || 0);
                            var p    = parseFloat(row.Payments || 0);
                            var b    = parseFloat(row.Balance  || 0);
                            var mn   = AB_MONTHS[i];

                            /*
                             * B9 FIX: if all four are zero, this month has no data
                             * (account didn't exist yet OR truly zero month).
                             * Leave cells as '—' — don't render rows of 0.00/0.00/0.00.
                             */
                            if (o === 0 && r === 0 && p === 0 && b === 0) continue;

                            anyData = true;
                            var balEl = document.getElementById(mn + '-balance');
                            document.getElementById(mn + '-opening').textContent  = fmtNum(o);
                            document.getElementById(mn + '-received').textContent = fmtNum(r);
                            document.getElementById(mn + '-payment').textContent  = fmtNum(p);
                            balEl.textContent = fmtNum(b);
                            /* colour: red if negative, green if positive */
                            balEl.className   = 'ab-ledger-num ' + (b < 0 ? 'ab-neg' : b > 0 ? 'ab-pos' : '');
                        }

                        document.getElementById('total-received').textContent = fmtNum(res.totalReceived || 0);
                        document.getElementById('total-payments').textContent = fmtNum(res.totalPayments || 0);

                        if (!anyData) abToast('No transactions found for this account yet.', 'info');
                    },
                    error: function () {
                        if (loadingEl) loadingEl.style.display = 'none';
                        abToast('Could not load monthly data.', 'warning');
                    }
                });
            },
            error: function () {
                btn.disabled  = false;
                btn.innerHTML = '<i class="fa fa-eye"></i> View';
                abToast('Error loading account data.', 'error');
            }
        });
    });

}); /* end DOMContentLoaded */
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
* { box-sizing: border-box; }

/* Shell */
.ab-wrap { font-family:'DM Sans',sans-serif; background:var(--ab-bg); color:var(--ab-text); padding:24px 20px 60px; min-height:100vh; }

/* Top bar */
.ab-topbar { display:flex; align-items:flex-start; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:22px; }
.ab-page-title { font-family:'Playfair Display',serif; font-size:24px; font-weight:700; color:var(--ab-navy); display:flex; align-items:center; gap:10px; margin:0 0 5px; }
.ab-page-title i { color:var(--ab-teal); }
.ab-breadcrumb { display:flex; align-items:center; list-style:none; margin:0; padding:0; font-size:12.5px; color:var(--ab-muted); }
.ab-breadcrumb a { color:var(--ab-teal); text-decoration:none; font-weight:500; }
.ab-breadcrumb li+li::before { content:'/'; margin:0 7px; color:#cbd5e1; }
.ab-session-badge { display:flex; flex-direction:column; align-items:flex-end; gap:2px; }
.ab-badge-label { font-size:10px; font-weight:700; letter-spacing:.6px; text-transform:uppercase; color:var(--ab-muted); }
.ab-badge-val { font-family:'Playfair Display',serif; font-size:18px; font-weight:700; color:var(--ab-navy); line-height:1; }

/* Card */
.ab-card { background:var(--ab-white); border-radius:var(--ab-radius); box-shadow:var(--ab-shadow); border:1px solid var(--ab-border); overflow:hidden; margin-bottom:16px; }
.ab-card-head { display:flex; align-items:center; gap:9px; padding:13px 18px; border-bottom:1px solid var(--ab-border); background:linear-gradient(90deg,var(--ab-sky) 0%,var(--ab-white) 100%); }
.ab-step { width:24px; height:24px; border-radius:50%; background:var(--ab-teal); color:#fff; font-size:12px; font-weight:700; display:flex; align-items:center; justify-content:center; flex-shrink:0; }
.ab-card-head i { color:var(--ab-teal); }
.ab-card-head h3 { font-family:'Playfair Display',serif; font-size:14.5px; font-weight:700; color:var(--ab-navy); margin:0; }
.ab-card-body { padding:18px; }

/* Layout */
.ab-main-layout { display:grid; grid-template-columns:380px 1fr; gap:18px; align-items:start; }
@media (max-width:900px) { .ab-main-layout { grid-template-columns:1fr; } }

/* Form */
.ab-field { display:flex; flex-direction:column; gap:4px; margin-bottom:14px; }
.ab-label { font-size:11px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; color:var(--ab-muted); }
.ab-req { color:var(--ab-red); }
.ab-input, .ab-select { height:38px; padding:0 10px; border:1.5px solid var(--ab-border); border-radius:8px; font-size:13.5px; font-family:'DM Sans',sans-serif; color:var(--ab-text); background:#fafcff; outline:none; transition:border-color .13s,box-shadow .13s; width:100%; }
.ab-select { padding-right:32px; appearance:none; cursor:pointer; }
.ab-select-wrap { position:relative; }
.ab-select-wrap::after { content:'\f078'; font-family:'Font Awesome 5 Free'; font-weight:900; position:absolute; right:10px; top:50%; transform:translateY(-50%); color:var(--ab-muted); font-size:10px; pointer-events:none; }
.ab-input:focus, .ab-select:focus { border-color:var(--ab-teal); box-shadow:0 0 0 3px rgba(14,116,144,.1); }
.ab-field-hint { font-size:11px; color:var(--ab-muted); margin-top:2px; }
.ab-field-ok   { font-size:11px; color:var(--ab-green); margin-top:2px; font-weight:600; }
.ab-field-err  { font-size:11px; color:var(--ab-red);   margin-top:2px; font-weight:600; }

/* Buttons */
.ab-btn { display:inline-flex; align-items:center; justify-content:center; gap:7px; padding:9px 20px; border-radius:8px; border:none; font-family:'DM Sans',sans-serif; font-size:13px; font-weight:600; cursor:pointer; transition:opacity .13s,transform .1s; white-space:nowrap; }
.ab-btn:hover:not(:disabled) { opacity:.86; transform:translateY(-1px); }
.ab-btn:disabled, .ab-btn.ab-btn-disabled { opacity:.4; cursor:not-allowed; transform:none; pointer-events:none; }
.ab-btn-navy  { background:var(--ab-navy);  color:#fff; }
.ab-btn-teal  { background:var(--ab-teal);  color:#fff; }
.ab-btn-red   { background:var(--ab-red);   color:#fff; }
.ab-btn-amber { background:var(--ab-amber); color:#fff; }
.ab-btn-ghost { background:var(--ab-white); color:var(--ab-text); border:1.5px solid var(--ab-border); }
.ab-btn-sm    { padding:6px 14px; font-size:12px; }
.ab-btn-row   { display:flex; gap:8px; flex-wrap:wrap; margin-top:4px; }

/* Table */
.ab-table-wrap { overflow-x:auto; border-radius:8px; border:1px solid var(--ab-border); }
.ab-table { width:100%; border-collapse:collapse; font-size:13px; }
.ab-table thead th { background:var(--ab-navy); color:rgba(255,255,255,.85); font-size:11px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; padding:11px 14px; text-align:left; white-space:nowrap; }
.ab-table thead th:first-child { border-radius:8px 0 0 0; }
.ab-table thead th:last-child  { border-radius:0 8px 0 0; }
.ab-table tbody tr { border-bottom:1px solid var(--ab-border); cursor:pointer; transition:background .1s; }
.ab-table tbody tr:hover { background:var(--ab-sky); }
.ab-table tbody tr.ab-row-selected { background:linear-gradient(90deg,var(--ab-sky) 0%,#bae6fd 100%); border-left:3px solid var(--ab-teal); }
.ab-table tbody td { padding:10px 14px; color:var(--ab-text); }
.ab-table tbody td:first-child { color:var(--ab-muted); font-size:12px; }
.ab-badge-subgroup { display:inline-flex; align-items:center; padding:2px 10px; border-radius:20px; font-size:11px; font-weight:600; background:#f0fdf4; color:var(--ab-green); border:1px solid #bbf7d0; }
.ab-table-actions { display:flex; gap:8px; padding:12px 14px; background:#f8fafc; border-top:1px solid var(--ab-border); border-radius:0 0 8px 8px; }

/* Info tiles */
.ab-info-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(220px,1fr)); gap:12px; margin-bottom:20px; }
.ab-info-tile { background:var(--ab-white); border:1px solid var(--ab-border); border-radius:10px; padding:12px 16px; box-shadow:var(--ab-shadow); }
.ab-info-tile-label { font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.5px; color:var(--ab-muted); margin-bottom:4px; }
.ab-info-tile-val { font-size:14px; font-weight:600; color:var(--ab-navy); }

/* Ledger */
.ab-ledger-wrap { overflow-x:auto; border-radius:8px; border:1px solid var(--ab-border); }
.ab-ledger { width:100%; border-collapse:collapse; font-size:13px; }
.ab-ledger thead th { background:var(--ab-navy); color:rgba(255,255,255,.85); font-size:11px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; padding:11px 14px; text-align:right; }
.ab-ledger thead th:first-child { text-align:left; border-radius:8px 0 0 0; }
.ab-ledger thead th:last-child  { border-radius:0 8px 0 0; }
.ab-ledger tbody tr { border-bottom:1px solid var(--ab-border); transition:background .1s; }
.ab-ledger tbody tr:hover { background:var(--ab-sky); }
.ab-ledger tbody td { padding:9px 14px; }
.ab-ledger tbody td:first-child { font-weight:600; color:var(--ab-navy); }
.ab-ledger-num { text-align:right; font-variant-numeric:tabular-nums; }
.ab-ledger .ab-neg { color:var(--ab-red);   font-weight:700; }
.ab-ledger .ab-pos { color:var(--ab-green); font-weight:700; }
.ab-ledger tfoot td { background:var(--ab-navy); color:rgba(255,255,255,.9); padding:10px 14px; font-weight:700; font-size:13px; }
.ab-ledger tfoot td:not(:first-child) { text-align:right; font-variant-numeric:tabular-nums; }
.ab-ledger tfoot td:first-child { border-radius:0 0 0 8px; }
.ab-ledger tfoot td:last-child  { border-radius:0 0 8px 0; }

/* Modal */
.ab-overlay { position:fixed; inset:0; background:rgba(11,31,58,.55); z-index:9000; display:none; align-items:center; justify-content:center; padding:16px; }
.ab-overlay.open { display:flex; }
.ab-modal { background:var(--ab-white); border-radius:var(--ab-radius); box-shadow:0 20px 60px rgba(0,0,0,.25); width:100%; max-width:460px; animation:ab-modal-in .2s ease; overflow:hidden; }
.ab-modal-head { background:var(--ab-navy); color:#fff; padding:14px 20px; display:flex; align-items:center; justify-content:space-between; }
.ab-modal-head h4 { font-family:'Playfair Display',serif; font-size:16px; font-weight:700; margin:0; display:flex; align-items:center; gap:8px; }
.ab-modal-close { background:none; border:none; color:rgba(255,255,255,.65); font-size:22px; cursor:pointer; line-height:1; transition:color .13s; }
.ab-modal-close:hover { color:#fff; }
.ab-modal-body   { padding:24px 20px; font-size:14px; color:var(--ab-text); line-height:1.6; }
.ab-modal-footer { padding:14px 20px; border-top:1px solid var(--ab-border); display:flex; justify-content:flex-end; gap:8px; }
@keyframes ab-modal-in { from{transform:translateY(14px);opacity:0} to{transform:translateY(0);opacity:1} }

/* Toast */
.ab-toast-wrap { position:fixed; top:20px; right:20px; z-index:99999; display:flex; flex-direction:column; gap:8px; pointer-events:none; }
.ab-toast { padding:11px 16px; border-radius:10px; color:#fff; font-family:'DM Sans',sans-serif; font-size:13px; font-weight:600; box-shadow:0 4px 18px rgba(0,0,0,.2); display:flex; align-items:center; gap:8px; animation:ab-toast-in .22s ease; max-width:320px; pointer-events:auto; transition:opacity .3s; }
.ab-toast.ab-hiding { opacity:0; }
.ab-toast-success { background:var(--ab-green); }
.ab-toast-error   { background:var(--ab-red); }
.ab-toast-warning { background:var(--ab-amber); }
.ab-toast-info    { background:var(--ab-teal); }
@keyframes ab-toast-in { from{transform:translateX(20px);opacity:0} to{transform:translateX(0);opacity:1} }

/* Misc */
.ab-spin { animation:ab-spin .7s linear infinite; display:inline-block; }
@keyframes ab-spin { to{transform:rotate(360deg)} }
.ab-divider { border:none; border-top:1px solid var(--ab-border); margin:16px 0; }
.ab-text-muted { color:var(--ab-muted); font-size:12.5px; }

/* Print */
@media print {
    .ab-topbar, .ab-main-layout, #ab-view-page .ab-btn-row, .ab-toast-wrap, .ab-overlay { display:none !important; }
    #ab-view-page { display:block !important; }
    .ab-card { box-shadow:none; border:none; }
}
</style>