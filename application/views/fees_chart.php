<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="fm-wrap">

    <!-- ══ TOP BAR ══ -->
    <div class="fm-topbar">
        <div class="fm-topbar-left">
            <h1 class="fm-page-title">
                <i class="fa fa-bar-chart"></i> Fees Chart
            </h1>
            <ol class="fm-breadcrumb">
                <li><a href="<?= base_url('dashboard') ?>"><i class="fa fa-home"></i> Dashboard</a></li>
                <li><a href="#">Fees</a></li>
                <li>Fees Chart</li>
            </ol>
        </div>
    </div>

    <!-- ══ FILTER CARD ══ -->
    <div class="fm-card">
        <div class="fm-card-head">
            <i class="fa fa-filter"></i>
            <h3>Select Class &amp; Section</h3>
        </div>
        <div class="fm-card-body">
            <div class="fm-filter-row">

                <!-- Class select -->
                <div class="fm-filter-group">
                    <label class="fm-label">Class <span class="fm-req">*</span></label>
                    <div class="fm-select-wrap">
                        <select class="fm-select" id="selectClass" autocomplete="off">
                            <option value="">— Select Class —</option>
                            <?php foreach ($classes as $className): ?>
                            <option value="<?= htmlspecialchars($className) ?>">
                                <?= htmlspecialchars($className) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <i class="fa fa-chevron-down fm-select-arrow"></i>
                    </div>
                </div>

                <!-- Section select (populated by JS) -->
                <div class="fm-filter-group">
                    <label class="fm-label">Section <span class="fm-req">*</span></label>
                    <div class="fm-select-wrap">
                        <select class="fm-select" id="selectSection" disabled autocomplete="off">
                            <option value="">— Select Class First —</option>
                        </select>
                        <i class="fa fa-chevron-down fm-select-arrow"></i>
                    </div>
                </div>

                <!-- Load button -->
                <div class="fm-filter-group fm-filter-btn-group">
                    <label class="fm-label">&nbsp;</label>
                    <button class="fm-btn fm-btn-primary" id="searchFees">
                        <i class="fa fa-search"></i> Load Fees
                    </button>
                </div>

                <!-- Copy April button -->
                <div class="fm-filter-group fm-filter-btn-group">
                    <label class="fm-label">&nbsp;</label>
                    <button class="fm-btn fm-btn-amber" id="copycolfees"
                            title="Copy April values to all months" disabled>
                        <i class="fa fa-copy"></i> Copy April →
                    </button>
                </div>

            </div>

            <!-- Selection summary badge -->
            <div class="fm-selection-badge" id="selectionBadge" style="display:none;">
                <i class="fa fa-check-circle"></i>
                <span id="selectionBadgeText"></span>
            </div>
        </div>
    </div>

    <!-- ══ MONTHLY FEES TABLE ══ -->
    <div class="fm-card" id="monthlyCard">
        <div class="fm-card-head">
            <i class="fa fa-calendar"></i>
            <h3>Monthly Fees Chart</h3>
            <div class="fm-card-head-right">
                <span class="fm-sum-pill" id="monthlyGrandPill">Total: <strong>0.00</strong></span>
            </div>
        </div>
        <div class="fm-table-scroll">
            <table class="fm-table" id="feesTable">
                <thead>
                    <tr>
                        <th class="fm-th-title">Fee Title</th>
                        <th>Apr</th><th>May</th><th>Jun</th><th>Jul</th>
                        <th>Aug</th><th>Sep</th><th>Oct</th><th>Nov</th>
                        <th>Dec</th><th>Jan</th><th>Feb</th><th>Mar</th>
                        <th class="fm-th-total">Total</th>
                    </tr>
                </thead>
                <tbody id="monthlyTbody">
                    <tr>
                        <td colspan="14" class="fm-placeholder-cell">
                            <div class="fm-placeholder">
                                <i class="fa fa-bar-chart"></i>
                                <p>Select a class and section, then click <strong>Load Fees</strong>.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="fm-tfoot-row">
                        <td><strong>Monthly Total</strong></td>
                        <?php for ($i = 0; $i < 12; $i++): ?>
                        <td class="monthly-total fm-tfoot-num"><strong>0.00</strong></td>
                        <?php endfor; ?>
                        <td class="overall-total fm-tfoot-num"><strong>0.00</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ══ YEARLY FEES TABLE ══ -->
    <div class="fm-card" id="yearlyCard">
        <div class="fm-card-head">
            <i class="fa fa-star"></i>
            <h3>Yearly Fees Chart</h3>
            <div class="fm-card-head-right">
                <span class="fm-sum-pill" id="yearlyTotalPill">Total: <strong>0.00</strong></span>
            </div>
        </div>
        <div class="fm-table-scroll">
            <table class="fm-table" id="feesTable2">
                <thead>
                    <tr>
                        <th style="width:54px;">S.No.</th>
                        <th>Fee Title</th>
                        <th style="width:160px;">Amount (₹)</th>
                    </tr>
                </thead>
                <tbody id="yearlyTbody">
                    <tr>
                        <td colspan="3" class="fm-placeholder-cell">
                            <div class="fm-placeholder">
                                <i class="fa fa-star-o"></i>
                                <p>Yearly fees will appear here.</p>
                            </div>
                        </td>
                    </tr>
                </tbody>
                <tfoot>
                    <tr class="fm-tfoot-row">
                        <td colspan="2"><strong>Yearly Total</strong></td>
                        <td class="fm-tfoot-num" id="totalFeesValue"><strong>0.00</strong></td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </div>

    <!-- ══ OVERALL BAR ══ -->
    <div class="fm-total-bar">
        <div class="fm-total-bar-left">
            <span class="fm-total-bar-label">OVERALL TOTAL (Monthly + Yearly)</span>
            <span class="fm-total-bar-value" id="totalFeesCell">₹ 0.00</span>
        </div>
        <button type="button" id="saveButton"
                class="fm-btn fm-btn-success" onclick="saveUpdatedFees()" disabled>
            <i class="fa fa-save"></i> Save All Fees
        </button>
    </div>

</div><!-- /.fm-wrap -->

<!-- Toast container -->
<div class="fm-toast-wrap" id="fmToastWrap"></div>
</div><!-- /.content-wrapper -->


<script>
/* ================================================================
   fees_chart.php  —  JS
   Classes and sections come from PHP (fetched from Firebase
   Schools/{schoolName}/{year}/Classes in the controller).
================================================================ */

/*
 * Section map injected by PHP controller.
 * Shape: { "Class 8th": ["Section A", "Section B"], ... }
 * Keys are full class names as stored in Firebase.
 * Values are full section names ("Section A", not just "A").
 */
var SECTION_MAP = <?php echo json_encode($sections ?? []); ?>;

/* ── Helpers ── */
function numberFormat(n) {
    return parseFloat(n || 0).toLocaleString('en-IN', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function showToast(msg, type) {
    var wrap = document.getElementById('fmToastWrap');
    if (!wrap) return;
    var el = document.createElement('div');
    el.className = 'fm-toast fm-toast-' + (type || 'info');
    el.innerHTML = '<i class="fa fa-' + {
        success: 'check-circle',
        error:   'times-circle',
        warning: 'exclamation-triangle',
        info:    'info-circle'
    }[type || 'info'] + '"></i> ' + msg;
    wrap.appendChild(el);
    setTimeout(function () {
        el.classList.add('fm-toast-hide');
        setTimeout(function () { el.remove(); }, 350);
    }, 3200);
}

/* ── DOM ready ── */
document.addEventListener('DOMContentLoaded', function () {

    /* ── Force-reset selects on every page load so browser autocomplete
       cannot pre-fill a previous class/section selection. */
    document.getElementById('selectClass').value = '';
    document.getElementById('selectSection').innerHTML = '<option value="">— Select Class First —</option>';
    document.getElementById('selectSection').disabled = true;
    if (document.getElementById('saveButton'))  document.getElementById('saveButton').disabled = true;
    if (document.getElementById('copycolfees')) document.getElementById('copycolfees').disabled = true;
    if (document.getElementById('selectionBadge')) document.getElementById('selectionBadge').style.display = 'none';


    var classSelect   = document.getElementById('selectClass');
    var sectionSelect = document.getElementById('selectSection');
    var saveButton    = document.getElementById('saveButton');
    var copyButton    = document.getElementById('copycolfees');
    var badge         = document.getElementById('selectionBadge');
    var badgeText     = document.getElementById('selectionBadgeText');

    /* ────────────────────────────────────────────────────────
       CLASS → SECTION  population
       Reads directly from SECTION_MAP (injected from Firebase).
    ──────────────────────────────────────────────────────── */
    classSelect.addEventListener('change', function () {
        var cls = this.value;

        /* Reset section select */
        sectionSelect.innerHTML = '<option value="">— Select Section —</option>';
        sectionSelect.disabled  = true;
        saveButton.disabled     = true;
        if (copyButton) copyButton.disabled = true;
        if (badge) badge.style.display = 'none';

        if (!cls || !SECTION_MAP[cls] || SECTION_MAP[cls].length === 0) return;

        /*
         * SECTION_MAP[cls] contains full section names from Firebase:
         * e.g. ["Section A", "Section B"]
         * We display them as-is and pass the full name to the backend.
         */
        SECTION_MAP[cls].forEach(function (sec) {
            var opt = document.createElement('option');
            opt.value       = sec;          // "Section A" — full name for backend
            opt.textContent = sec;          // display as-is
            sectionSelect.appendChild(opt);
        });

        sectionSelect.disabled = false;
    });

    /* ────────────────────────────────────────────────────────
       LOAD FEES button
    ──────────────────────────────────────────────────────── */
    document.getElementById('searchFees').addEventListener('click', function () {
        var cls = classSelect.value;
        var sec = sectionSelect.value;

        if (!cls) { showToast('Please select a class.', 'error');   return; }
        if (!sec) { showToast('Please select a section.', 'error'); return; }

        var btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Loading…';

        /*
         * Pass full names to the controller:
         *   class   = "Class 10th"
         *   section = "Section A"
         * Controller's fees_chart() GET handler uses these directly
         * to call feesPath($class, $section) and _getFees($class, $section).
         */
        var url = '<?= site_url('fees/fees_chart') ?>'
            + '?class='   + encodeURIComponent(cls)
            + '&section=' + encodeURIComponent(sec);

        fetch(url)
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-search"></i> Load Fees';

                if (data.error) {
                    showToast(data.error, 'error');
                    return;
                }

                populateMonthly(data.fees || {});
                populateYearly(data.fees  || {});
                updateTotalFees();

                saveButton.disabled = true; // only enable after edits
                if (copyButton) copyButton.disabled = false;

                /* Attach input listeners for change detection */
                document.querySelectorAll('.num-input').forEach(function (inp) {
                    inp.addEventListener('input', function () {
                        saveButton.disabled = false;
                        updateTotalFees();
                    });
                });

                /* Show selection badge */
                if (badge && badgeText) {
                    badgeText.textContent = cls + ' — ' + sec;
                    badge.style.display = 'flex';
                }

                showToast('Fees loaded for ' + cls + ' ' + sec, 'success');
            })
            .catch(function (err) {
                btn.disabled = false;
                btn.innerHTML = '<i class="fa fa-search"></i> Load Fees';
                showToast('Failed to load fee data. Please try again.', 'error');
                console.error('fees_chart fetch error:', err);
            });
    });

    /* ────────────────────────────────────────────────────────
       COPY APRIL → ALL MONTHS
    ──────────────────────────────────────────────────────── */
    if (copyButton) {
        copyButton.addEventListener('click', function () {
            var rows = document.querySelectorAll('#monthlyTbody tr');
            if (rows.length === 0 || rows[0].querySelectorAll('.num-input').length === 0) {
                showToast('Load fee data first.', 'error');
                return;
            }
            rows.forEach(function (row) {
                var inputs = row.querySelectorAll('.num-input');
                var aprilVal = parseFloat(inputs[0] ? inputs[0].value : 0) || 0;
                inputs.forEach(function (inp, i) { if (i > 0) inp.value = aprilVal; });
            });
            updateTotalFees();
            saveButton.disabled = false;
            showToast('April values copied to all months.', 'info');
        });
    }

}); /* end DOMContentLoaded */

/* ────────────────────────────────────────────────────────────
   populateMonthly
──────────────────────────────────────────────────────────── */
function populateMonthly(feesData) {
    var tbody = document.getElementById('monthlyTbody');
    tbody.innerHTML = '';

    var allMonths = [
        'April','May','June','July','August','September',
        'October','November','December','January','February','March'
    ];

    /* Collect all fee titles across all months */
    var titlesSet = new Set();
    allMonths.forEach(function (m) {
        var mf = feesData[m];
        if (mf && typeof mf === 'object') Object.keys(mf).forEach(function (t) { titlesSet.add(t); });
    });

    if (titlesSet.size === 0) {
        tbody.innerHTML = '<tr><td colspan="14" class="fm-placeholder-cell">'
            + '<div class="fm-placeholder"><i class="fa fa-exclamation-circle"></i>'
            + '<p>No monthly fee titles found. Add fees structure first.</p></div></td></tr>';
        return;
    }

    titlesSet.forEach(function (feeTitle) {
        var tr = document.createElement('tr');

        /* Title cell */
        var td0 = document.createElement('td');
        td0.className   = 'fm-td-title';
        td0.textContent = feeTitle;
        tr.appendChild(td0);

        /* Month input cells */
        allMonths.forEach(function (month) {
            var td = document.createElement('td');
            var val = (feesData[month] && feesData[month][feeTitle] !== undefined)
                      ? feesData[month][feeTitle] : 0;
            var inp = document.createElement('input');
            inp.type      = 'text';
            inp.className = 'num-input fm-num-input';
            inp.value     = parseFloat(val) || 0;
            inp.setAttribute('data-month', month);
            inp.setAttribute('data-title', feeTitle);
            td.appendChild(inp);
            tr.appendChild(td);
        });

        /* Row total cell */
        var tdTotal = document.createElement('td');
        tdTotal.className = 'total-cell fm-td-total';
        tr.appendChild(tdTotal);

        tbody.appendChild(tr);
    });
}

/* ────────────────────────────────────────────────────────────
   populateYearly
──────────────────────────────────────────────────────────── */
function populateYearly(feesData) {
    var tbody = document.getElementById('yearlyTbody');
    tbody.innerHTML = '';

    var yearlyFees = feesData['Yearly Fees'];
    if (!yearlyFees || typeof yearlyFees !== 'object' || Object.keys(yearlyFees).length === 0) {
        tbody.innerHTML = '<tr><td colspan="3" class="fm-placeholder-cell">'
            + '<div class="fm-placeholder"><i class="fa fa-star-o"></i>'
            + '<p>No yearly fee titles found.</p></div></td></tr>';
        return;
    }

    var sno = 1;
    Object.entries(yearlyFees).forEach(function (entry) {
        var feeTitle = entry[0];
        var feeValue = entry[1];
        var safeId   = 'yearly-' + feeTitle.replace(/[^a-zA-Z0-9_-]/g, '_');

        var tr = document.createElement('tr');
        tr.innerHTML = '<td class="fm-td-sno">' + (sno++) + '</td>'
                     + '<td class="fm-td-title">' + feeTitle + '</td>'
                     + '<td></td>';

        var inp = document.createElement('input');
        inp.type             = 'text';
        inp.id               = safeId;
        inp.className        = 'num-input fm-num-input yearly-fee-input';
        inp.value            = parseFloat(feeValue) || 0;
        inp.dataset.feeTitle = feeTitle;  // store exact original title
        tr.cells[2].appendChild(inp);

        tbody.appendChild(tr);
    });
}

/* ────────────────────────────────────────────────────────────
   updateTotalFees  — recalculates all row/column/grand totals
──────────────────────────────────────────────────────────── */
function updateTotalFees() {
    var monthlyTotals = Array(12).fill(0);
    var overallMonthly = 0;

    document.querySelectorAll('#monthlyTbody tr').forEach(function (row) {
        var inputs   = row.querySelectorAll('.num-input');
        var rowTotal = 0;
        inputs.forEach(function (inp, ci) {
            var v = parseFloat(inp.value) || 0;
            rowTotal        += v;
            monthlyTotals[ci] += v;
        });
        var tc = row.querySelector('.total-cell');
        if (tc) tc.textContent = numberFormat(rowTotal);
        overallMonthly += rowTotal;
    });

    /* Footer column totals */
    document.querySelectorAll('tfoot .monthly-total').forEach(function (el, i) {
        el.innerHTML = '<strong>' + numberFormat(monthlyTotals[i]) + '</strong>';
    });
    var overallCell = document.querySelector('tfoot .overall-total');
    if (overallCell) overallCell.innerHTML = '<strong>' + numberFormat(overallMonthly) + '</strong>';

    /* Yearly total */
    var yearlyTotal = 0;
    document.querySelectorAll('.yearly-fee-input').forEach(function (inp) {
        yearlyTotal += parseFloat(inp.value) || 0;
    });
    var tvEl = document.getElementById('totalFeesValue');
    if (tvEl) tvEl.innerHTML = '<strong>' + numberFormat(yearlyTotal) + '</strong>';

    /* Grand total pill + bar */
    var grand = overallMonthly + yearlyTotal;
    var tcEl = document.getElementById('totalFeesCell');
    if (tcEl) tcEl.textContent = '₹ ' + numberFormat(grand);

    var mPill = document.getElementById('monthlyGrandPill');
    if (mPill) mPill.innerHTML = 'Total: <strong>' + numberFormat(overallMonthly) + '</strong>';

    var yPill = document.getElementById('yearlyTotalPill');
    if (yPill) yPill.innerHTML = 'Total: <strong>' + numberFormat(yearlyTotal) + '</strong>';
}

/* ────────────────────────────────────────────────────────────
   saveUpdatedFees
   Sends: { class: "Class 10th", section: "Section A", fees: {...} }
   Matches save_updated_fees() controller expectation exactly.
──────────────────────────────────────────────────────────── */
function saveUpdatedFees() {
    var cls = document.getElementById('selectClass').value;
    var sec = document.getElementById('selectSection').value;

    if (!cls || !sec) {
        showToast('Please select a class and section first.', 'error');
        return;
    }

    var allMonths = [
        'April','May','June','July','August','September',
        'October','November','December','January','February','March'
    ];

    /* Build fees object */
    var updatedFees = {};
    document.querySelectorAll('#monthlyTbody tr').forEach(function (row) {
        var inputs = row.querySelectorAll('.num-input');
        inputs.forEach(function (inp) {
            var month = inp.dataset.month;
            var title = inp.dataset.title;
            if (!month || !title) return;
            if (!updatedFees[month]) updatedFees[month] = {};
            updatedFees[month][title] = parseFloat(inp.value) || 0;
        });
    });

    /* Build yearly fees object */
    var yearlyFees = {};
    document.querySelectorAll('.yearly-fee-input').forEach(function (inp) {
        var title = inp.dataset.feeTitle;
        if (title) yearlyFees[title] = parseFloat(inp.value) || 0;
    });
    updatedFees['Yearly Fees'] = yearlyFees;

    var btn = document.getElementById('saveButton');
    btn.disabled  = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

    /* ── Read CSRF from meta tags (set by include/header.php) ── */
    var CSRF_NAME = document.querySelector('meta[name="csrf-name"]').getAttribute('content');
    var CSRF_HASH = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    /*
     * Send as FormData — NOT JSON.
     * This means CI's built-in CSRF filter can read the token
     * from $_POST normally. No exclusions. No bypasses.
     * fees is JSON-stringified into a single field so the
     * controller can decode it cleanly.
     */
    var fd = new FormData();
    fd.append(CSRF_NAME,  CSRF_HASH);              // ← CI built-in filter reads this
    fd.append('class',    cls);
    fd.append('section',  sec);
    fd.append('fees',     JSON.stringify(updatedFees)); // fees as JSON string field

    fetch('<?= site_url('fees/save_updated_fees') ?>', {
        method:  'POST',
        body:    fd,
        headers: {
            'X-CSRF-Token':     CSRF_HASH,         // ← MY_Controller double-checks this
            'X-Requested-With': 'XMLHttpRequest'
        }
    })
    .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        return r.json();
    })
    .then(function (res) {
        if (res.status === 'success') {
            showToast('Fees saved successfully!', 'success');
            btn.innerHTML = '<i class="fa fa-save"></i> Save All Fees';
            btn.disabled  = true;
        } else {
            showToast(res.message || 'Save failed. Please try again.', 'error');
            btn.innerHTML = '<i class="fa fa-save"></i> Save All Fees';
            btn.disabled  = false;
        }
    })
    .catch(function (err) {
        showToast('Network error while saving.', 'error');
        btn.innerHTML = '<i class="fa fa-save"></i> Save All Fees';
        btn.disabled  = false;
        console.error('saveUpdatedFees error:', err);
    });
}

</script>



<style>
@import url('https://fonts.googleapis.com/css2?family=Instrument+Sans:wght@400;500;600;700&family=Lora:wght@500;600;700&display=swap');

:root {
    --fm-navy:   #0c1e38;
    --fm-teal:   #0f766e;
    --fm-sky:    #e6f4f1;
    --fm-green:  #15803d;
    --fm-amber:  #d97706;
    --fm-red:    #dc2626;
    --fm-text:   #1a2535;
    --fm-muted:  #64748b;
    --fm-border: #e2e8f0;
    --fm-white:  #ffffff;
    --fm-bg:     #f0f7f5;
    --fm-shadow: 0 1px 12px rgba(12,30,56,.07);
    --fm-radius: 12px;
}

/* ── Shell ── */
.fm-wrap {
    font-family: 'Instrument Sans', sans-serif;
    background: var(--fm-bg);
    color: var(--fm-text);
    padding: 26px 22px 60px;
    min-height: 100vh;
}

/* ── Top bar ── */
.fm-topbar {
    display: flex; align-items: flex-start;
    justify-content: space-between; gap: 12px;
    margin-bottom: 24px;
}
.fm-page-title {
    font-family: 'Lora', serif;
    font-size: 1.6rem; font-weight: 700;
    color: var(--fm-navy);
    display: flex; align-items: center; gap: 10px;
    margin: 0 0 6px;
}
.fm-page-title i { color: var(--fm-teal); }

.fm-breadcrumb {
    display: flex; align-items: center; gap: 6px;
    font-size: 13px; color: var(--fm-muted);
    list-style: none; margin: 0; padding: 0;
}
.fm-breadcrumb a { color: var(--fm-teal); text-decoration: none; font-weight: 500; }
.fm-breadcrumb a:hover { text-decoration: underline; }
.fm-breadcrumb li::before { content: '/'; margin-right: 6px; color: var(--fm-border); }
.fm-breadcrumb li:first-child::before { display: none; }

/* ── Card ── */
.fm-card {
    background: var(--fm-white);
    border-radius: var(--fm-radius);
    box-shadow: var(--fm-shadow);
    border: 1px solid var(--fm-border);
    margin-bottom: 18px;
    overflow: hidden;
}
.fm-card-head {
    display: flex; align-items: center; gap: 10px;
    padding: 14px 20px;
    border-bottom: 1px solid var(--fm-border);
    background: linear-gradient(90deg, var(--fm-sky) 0%, var(--fm-white) 100%);
}
.fm-card-head i { color: var(--fm-teal); font-size: 15px; flex-shrink: 0; }
.fm-card-head h3 {
    margin: 0; flex: 1;
    font-family: 'Lora', serif;
    font-size: 15px; font-weight: 600;
    color: var(--fm-navy);
}
.fm-card-head-right { margin-left: auto; }
.fm-sum-pill {
    background: var(--fm-sky);
    border: 1px solid rgba(15,118,110,.2);
    color: var(--fm-teal);
    padding: 4px 12px; border-radius: 20px;
    font-size: 13px;
}
.fm-sum-pill strong { font-weight: 700; }

.fm-card-body { padding: 20px; }

/* ── Filter row ── */
.fm-filter-row {
    display: flex; align-items: flex-end; flex-wrap: wrap; gap: 16px;
}
.fm-filter-group { display: flex; flex-direction: column; gap: 5px; min-width: 180px; }
.fm-filter-btn-group { min-width: auto; }

.fm-label {
    font-size: 12px; font-weight: 600;
    text-transform: uppercase; letter-spacing: .5px;
    color: var(--fm-muted);
}
.fm-req { color: var(--fm-red); margin-left: 2px; }

.fm-select-wrap { position: relative; }
.fm-select {
    width: 100%; height: 40px;
    padding: 0 32px 0 12px;
    border: 1.5px solid var(--fm-border);
    border-radius: 8px; font-size: 13.5px;
    color: var(--fm-text); background: var(--fm-white);
    font-family: 'Instrument Sans', sans-serif;
    outline: none; cursor: pointer;
    appearance: none;
    transition: border-color .14s, box-shadow .14s;
}
.fm-select:focus { border-color: var(--fm-teal); box-shadow: 0 0 0 3px rgba(15,118,110,.1); }
.fm-select:disabled { background: var(--fm-bg); cursor: not-allowed; color: var(--fm-muted); }
.fm-select-arrow {
    position: absolute; right: 10px; top: 50%;
    transform: translateY(-50%);
    color: var(--fm-muted); font-size: 12.5px;
    pointer-events: none;
}

/* ── Selection badge ── */
.fm-selection-badge {
    margin-top: 12px;
    display: flex; align-items: center; gap: 8px;
    padding: 8px 14px;
    background: #d1fae5; border: 1px solid rgba(15,118,110,.25);
    border-radius: 8px;
    font-size: 13px; color: var(--fm-green); font-weight: 600;
    width: fit-content;
}
.fm-selection-badge i { font-size: 14px; }

/* ── Buttons ── */
.fm-btn {
    display: inline-flex; align-items: center; gap: 7px;
    height: 40px; padding: 0 18px;
    border-radius: 8px; border: none;
    font-size: 13.5px; font-weight: 600;
    cursor: pointer; white-space: nowrap;
    font-family: 'Instrument Sans', sans-serif;
    transition: opacity .13s, transform .1s;
}
.fm-btn:hover:not(:disabled) { opacity: .86; transform: translateY(-1px); }
.fm-btn:disabled { opacity: .45; cursor: not-allowed; transform: none; }
.fm-btn-primary { background: var(--fm-teal);  color: #fff; box-shadow: 0 2px 10px rgba(15,118,110,.28); }
.fm-btn-amber   { background: var(--fm-amber); color: #fff; box-shadow: 0 2px 10px rgba(217,119,6,.22); }
.fm-btn-success { background: var(--fm-green); color: #fff; box-shadow: 0 2px 10px rgba(21,128,61,.22); }

/* ── Table ── */
.fm-table-scroll {
    overflow-x: auto;
    max-height: 440px;
    overflow-y: auto;
}

.fm-table {
    width: 100%; border-collapse: collapse;
    font-size: 13px;
}
.fm-table thead {
    position: sticky; top: 0; z-index: 2;
}
.fm-table thead tr {
    background: var(--fm-navy);
}
.fm-table thead th {
    padding: 10px 10px;
    font-size: 12.5px; font-weight: 700;
    text-transform: uppercase; letter-spacing: .5px;
    color: rgba(255,255,255,.85);
    white-space: nowrap; border: none;
}
.fm-th-title { min-width: 150px; text-align: left; }
.fm-th-total { min-width: 80px; }

.fm-table tbody tr {
    border-bottom: 1px solid var(--fm-border);
    transition: background .1s;
}
.fm-table tbody tr:hover { background: var(--fm-sky); }
.fm-table td {
    padding: 7px 8px;
    border: none; vertical-align: middle;
    text-align: center;
}
.fm-td-title { text-align: left; padding-left: 14px; font-weight: 500; color: var(--fm-navy); white-space: nowrap; }
.fm-td-total { font-weight: 600; color: var(--fm-teal); }
.fm-td-sno   { color: var(--fm-muted); font-size: 12px; }

/* ── Number inputs inside table ── */
.fm-num-input {
    width: 74px; height: 30px;
    padding: 0 6px; text-align: center;
    border: 1.5px solid var(--fm-border);
    border-radius: 5px; font-size: 13px;
    color: var(--fm-text);
    background: var(--fm-bg);
    font-family: 'Instrument Sans', sans-serif;
    outline: none;
    transition: border-color .12s, box-shadow .12s;
}
.fm-num-input:focus {
    border-color: var(--fm-teal);
    box-shadow: 0 0 0 2px rgba(15,118,110,.12);
    background: #fff;
}

/* Yearly table input - wider */
#feesTable2 .fm-num-input { width: 120px; }

/* ── Footer row ── */
.fm-tfoot-row { background: var(--fm-sky); }
.fm-tfoot-row td {
    padding: 9px 8px;
    font-size: 12.5px; color: var(--fm-navy);
    border-top: 2px solid var(--fm-border);
}
.fm-tfoot-num { text-align: center; color: var(--fm-teal); }

/* ── Placeholder ── */
.fm-placeholder-cell { padding: 40px 20px !important; }
.fm-placeholder {
    display: flex; flex-direction: column;
    align-items: center; justify-content: center;
    gap: 10px; text-align: center;
    color: var(--fm-muted);
}
.fm-placeholder i { font-size: 36px; opacity: .25; }
.fm-placeholder p { margin: 0; font-size: 14px; }

/* ── Total bar ── */
.fm-total-bar {
    display: flex; align-items: center;
    justify-content: space-between; flex-wrap: wrap; gap: 14px;
    background: var(--fm-navy);
    border-radius: var(--fm-radius);
    padding: 18px 24px;
    box-shadow: 0 4px 16px rgba(12,30,56,.18);
}
.fm-total-bar-left { display: flex; flex-direction: column; gap: 4px; }
.fm-total-bar-label {
    font-size: 12.5px; font-weight: 700;
    letter-spacing: .7px; text-transform: uppercase;
    color: rgba(255,255,255,.55);
}
.fm-total-bar-value {
    font-family: 'Lora', serif;
    font-size: 1.6rem; font-weight: 700;
    color: #fff;
}

/* ── Toast ── */
.fm-toast-wrap {
    position: fixed; top: 20px; right: 20px;
    z-index: 99999;
    display: flex; flex-direction: column; gap: 8px;
}
.fm-toast {
    padding: 12px 18px; border-radius: 10px;
    font-size: 13.5px; font-weight: 500; color: #fff;
    box-shadow: 0 4px 14px rgba(0,0,0,.18);
    display: flex; align-items: center; gap: 9px;
    animation: fm-toast-in .22s ease;
    max-width: 320px;
    transition: opacity .3s;
}
.fm-toast-hide { opacity: 0; }
@keyframes fm-toast-in {
    from { transform: translateX(16px); opacity: 0; }
    to   { transform: translateX(0);    opacity: 1; }
}
.fm-toast-success { background: var(--fm-green); }
.fm-toast-error   { background: var(--fm-red);   }
.fm-toast-warning { background: var(--fm-amber);  }
.fm-toast-info    { background: var(--fm-teal);   }

@media (max-width: 768px) {
    .fm-filter-row { flex-direction: column; }
    .fm-filter-group { min-width: 100%; }
    .fm-total-bar { flex-direction: column; align-items: flex-start; }
    .fm-num-input { width: 58px; }
}
</style>





