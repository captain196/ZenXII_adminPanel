<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
// ── Pre-compute stats (PHP side, no JS needed) ─────────────────────
$totalClasses   = 0;
$grandCollected = 0;
$bestClass      = ['name' => '—', 'total' => 0];
$worstClass     = ['name' => '—', 'total' => PHP_INT_MAX];
$colTotals      = array_fill(0, 12, 0);
$grandTotal     = 0;

if (!empty($fees_record_matrix) && is_array($fees_record_matrix)) {
    $totalClasses = count($fees_record_matrix);
    foreach ($fees_record_matrix as &$row) {
        $t = (float)($row['total'] ?? 0);
        $grandCollected += $t;
        if ($t > $bestClass['total'])  $bestClass  = ['name' => $row['class'] ?? '—', 'total' => $t];
        if ($t < $worstClass['total']) $worstClass = ['name' => $row['class'] ?? '—', 'total' => $t];
        foreach (($row['amounts'] ?? []) as $mi => $amt) {
            $colTotals[$mi] = ($colTotals[$mi] ?? 0) + (float)$amt;
            $grandTotal     += (float)$amt;
        }
        // Pre-split class label e.g. "Class 8th Section B" → class + section
        $label = $row['class'] ?? '';
        if (preg_match('/^(Class\s+\S+)\s+(Section\s+\S+)$/i', $label, $m)) {
            $row['class_part']   = trim($m[1]);
            $row['section_part'] = trim($m[2]);
        } else {
            $row['class_part']   = $label;
            $row['section_part'] = '';
        }
    }
    unset($row);
}
$worstFinal = ($worstClass['total'] === PHP_INT_MAX) ? ['name' => '—', 'total' => 0] : $worstClass;
?>



<div class="content-wrapper">
    <div class="fr-wrap">

        <!-- ── TOP BAR ── -->
        <div class="fr-topbar">
            <div>
                <h1 class="fr-page-title">
                    <i class="fa fa-bar-chart"></i> Fee Collection Records
                </h1>
                <ol class="fr-breadcrumb">
                    <li><a href="<?= base_url() ?>"><i class="fa fa-home"></i> Dashboard</a></li>
                    <li><a href="#">Fees</a></li>
                    <li>Collection Records</li>
                </ol>
            </div>
            <div class="fr-topbar-right">
                <a href="<?= site_url('fees/class_fees') ?>" class="fr-btn fr-btn-navy">
                    <i class="fa fa-users"></i> Class Due Fees
                </a>
                <a href="<?= site_url('fees/fees_counter') ?>" class="fr-btn fr-btn-primary">
                    <i class="fa fa-plus"></i> Fee Counter
                </a>
            </div>
        </div>

        <!-- ── STAT STRIP ── -->
        <div class="fr-stat-strip">
            <div class="fr-stat fr-stat-blue">
                <div class="fr-stat-icon"><i class="fa fa-building-o"></i></div>
                <div class="fr-stat-body">
                    <div class="fr-stat-label">Total Classes</div>
                    <div class="fr-stat-val"><?= $totalClasses ?></div>
                </div>
            </div>
            <div class="fr-stat fr-stat-green">
                <div class="fr-stat-icon"><i class="fa fa-inr"></i></div>
                <div class="fr-stat-body">
                    <div class="fr-stat-label">Total Collected</div>
                    <div class="fr-stat-val">₹<?= number_format($grandCollected) ?></div>
                </div>
            </div>
            <div class="fr-stat fr-stat-amber">
                <div class="fr-stat-icon"><i class="fa fa-trophy"></i></div>
                <div class="fr-stat-body">
                    <div class="fr-stat-label">Top Collecting Class</div>
                    <div class="fr-stat-val fr-stat-val-sm"><?= htmlspecialchars($bestClass['name']) ?></div>
                    <div class="fr-stat-sub">₹<?= number_format($bestClass['total']) ?></div>
                </div>
            </div>
            <div class="fr-stat fr-stat-red">
                <div class="fr-stat-icon"><i class="fa fa-arrow-down"></i></div>
                <div class="fr-stat-body">
                    <div class="fr-stat-label">Lowest Collecting Class</div>
                    <div class="fr-stat-val fr-stat-val-sm"><?= htmlspecialchars($worstFinal['name']) ?></div>
                    <div class="fr-stat-sub">
                        <?= $worstFinal['total'] > 0 ? '₹'.number_format($worstFinal['total']) : '' ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ── MATRIX CARD ── -->
        <div class="fr-card">
            <div class="fr-card-head">
                <div class="fr-card-head-left">
                    <i class="fa fa-table"></i>
                    <h3>Monthly Collection Matrix</h3>
                    <span class="fr-head-hint">
                        Click to select &nbsp;·&nbsp; Double-click to open class
                    </span>
                </div>
                <div class="fr-card-head-right">
                    <button class="fr-btn fr-btn-ghost fr-btn-sm" id="exportBtn">
                        <i class="fa fa-download"></i> Export CSV
                    </button>
                    <button class="fr-btn fr-btn-primary" id="viewClassBtn" disabled>
                        <i class="fa fa-eye"></i> View Class Fees
                    </button>
                </div>
            </div>

            <?php if (!empty($fees_record_matrix) && is_array($fees_record_matrix)): ?>

            <div class="fr-filter-bar">
                <div class="fr-search-wrap">
                    <i class="fa fa-search fr-search-icon"></i>
                    <input type="text" id="tableSearch" class="fr-search" placeholder="Filter by class name…"
                        autocomplete="off">
                </div>
                <span class="fr-row-count" id="rowCount"><?= count($fees_record_matrix) ?> class(es)</span>
            </div>

            <div class="fr-table-wrap">
                <table class="fr-table" id="feeTable">
                    <thead>
                        <tr>
                            <th class="fr-th-class" style="min-width:120px;">Class</th>
                            <th class="fr-th-class" style="min-width:100px;">Section</th>
                            <th>Apr</th>
                            <th>May</th>
                            <th>Jun</th>
                            <th>Jul</th>
                            <th>Aug</th>
                            <th>Sep</th>
                            <th>Oct</th>
                            <th>Nov</th>
                            <th>Dec</th>
                            <th>Jan</th>
                            <th>Feb</th>
                            <th>Mar</th>
                            <th class="fr-th-total">Year Total</th>
                        </tr>
                    </thead>
                    <tbody id="tableBody">
                        <?php foreach ($fees_record_matrix as $row):
                        if (!isset($row['class'], $row['amounts'])) continue;
                        $rowTotal = (float)($row['total'] ?? 0);
                        $maxAmt   = count($row['amounts']) ? (float)max($row['amounts']) : 0;
                    ?>
                        <tr class="fr-row" data-class="<?= htmlspecialchars($row['class_part']) ?>"
                            data-section="<?= htmlspecialchars($row['section_part']) ?>">
                            <td class="fr-td-class">
                                <div class="fr-class-cell">
                                    <span class="fr-class-dot"></span>
                                    <?= htmlspecialchars($row['class_part']) ?>
                                </div>
                            </td>
                            <td class="fr-td-class" style="font-weight:500;color:var(--fc-teal);">
                                <?= htmlspecialchars($row['section_part']) ?>
                            </td>
                            <?php foreach ($row['amounts'] as $mi => $amount):
                            $amount    = (float)$amount;
                            $pct       = $maxAmt > 0 ? ($amount / $maxAmt) * 100 : 0;
                            $heatCls   = $amount == 0 ? 'fr-cell-zero'
                                        : ($pct >= 75 ? 'fr-cell-high'
                                        : ($pct >= 35 ? 'fr-cell-mid' : 'fr-cell-low'));
                        ?>
                            <td class="<?= $heatCls ?>">
                                <?= $amount > 0 ? '₹'.number_format($amount) : '<span class="fr-cell-zero">—</span>' ?>
                            </td>
                            <?php endforeach; ?>
                            <td class="fr-td-total">₹<?= number_format($rowTotal) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="fr-td-class"><strong>Grand Total</strong></td>
                            <td class="fr-td-class"></td>
                            <?php foreach ($colTotals as $ct): ?>
                            <td><?= $ct > 0 ? '₹'.number_format($ct) : '<span style="opacity:.35">—</span>' ?></td>
                            <?php endforeach; ?>
                            <td class="fr-td-total">₹<?= number_format($grandTotal) ?></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <?php else: ?>
            <div class="fr-empty">
                <i class="fa fa-inbox"></i>
                <p>No fee collection records found for this session.</p>
                <p class="fr-empty-sub">Records appear once fees are submitted via the Fee Counter.</p>
            </div>
            <?php endif; ?>
        </div>

    </div><!-- /.fr-wrap -->
</div><!-- /.content-wrapper -->


<script>
document.addEventListener('DOMContentLoaded', function() {
    var table = document.getElementById('feeTable');
    var viewBtn = document.getElementById('viewClassBtn');
    var expBtn = document.getElementById('exportBtn');
    var search = document.getElementById('tableSearch');
    var rcEl = document.getElementById('rowCount');
    var picked = null;

    if (!table) return;

    var rows = Array.from(table.querySelectorAll('tbody .fr-row'));

    /* Row select / navigate */
    rows.forEach(function(row) {
        row.addEventListener('click', function() {
            if (picked) picked.classList.remove('fr-selected');
            row.classList.add('fr-selected');
            picked = row;
            if (viewBtn) viewBtn.disabled = false;
        });
        row.addEventListener('dblclick', function() {
            gotoClass(row);
        });
    });

    if (viewBtn) viewBtn.addEventListener('click', function() {
        if (picked) gotoClass(picked);
    });

    function gotoClass(row) {
    var cls = (row.dataset.class || '').replace(/^Class\s+/i, '');
    var section = (row.dataset.section || '').replace(/^Section\s+/i, '');
    var url = '<?= site_url("fees/class_fees") ?>?class=' + encodeURIComponent(cls);
    if (section) url += '&section=' + encodeURIComponent(section);
    window.location.href = url;
}

    /* Live search */
    if (search) {
        search.addEventListener('input', function() {
            var q = search.value.toLowerCase().trim();
            var n = 0;
            rows.forEach(function(row) {
                var show = !q || (row.dataset.class + ' ' + row.dataset.section).toLowerCase()
                    .includes(q);
                row.style.display = show ? '' : 'none';
                if (show) n++;
            });
            if (rcEl) rcEl.textContent = n + ' class(es)';
            if (picked && picked.style.display === 'none') {
                picked.classList.remove('fr-selected');
                picked = null;
                if (viewBtn) viewBtn.disabled = true;
            }
        });
    }

    /* CSV Export */
    if (expBtn) {
        expBtn.addEventListener('click', function() {
            var hdr = ['Class/Section', 'April', 'May', 'June', 'July', 'August', 'September',
                'October', 'November', 'December', 'January', 'February', 'March', 'Year Total'
            ];
            var lines = [hdr.join(',')];
            rows.forEach(function(row) {
                if (row.style.display === 'none') return;
                var line = Array.from(row.querySelectorAll('td')).map(function(td) {
                    return '"' + td.textContent.replace(/[₹,]/g, '').replace(/—/g, '0')
                        .trim() + '"';
                });
                lines.push(line.join(','));
            });
            var blob = new Blob([lines.join('\n')], {
                type: 'text/csv'
            });
            var a = document.createElement('a');
            a.href = URL.createObjectURL(blob);
            a.download = 'fee_collection_<?= date("Y-m-d") ?>.csv';
            a.click();
        });
    }
});
</script>


<style>
@import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap');

/* ── Tokens matching fees_counter exactly ── */
:root {
    --fc-navy: #0b1f3a;
    --fc-teal: #0e7490;
    --fc-sky: #e0f2fe;
    --fc-green: #16a34a;
    --fc-red: #dc2626;
    --fc-amber: #d97706;
    --fc-blue: #2563eb;
    --fc-text: #1e293b;
    --fc-muted: #64748b;
    --fc-border: #e2e8f0;
    --fc-bg: #f1f5f9;
    --fc-white: #ffffff;
    --fc-shadow: 0 1px 14px rgba(11, 31, 58, .08);
    --fc-radius: 12px;
}

*,
*::before,
*::after {
    box-sizing: border-box;
}

/* ── Wrapper ── */
.fr-wrap {
    font-family: 'DM Sans', sans-serif;
    background: var(--fc-bg);
    color: var(--fc-text);
    padding: 24px 20px 60px;
    min-height: 100vh;
}

/* ── Top bar ── */
.fr-topbar {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 22px;
}

.fr-page-title {
    font-family: 'Playfair Display', serif;
    font-size: 1.6rem;
    font-weight: 700;
    color: var(--fc-navy);
    display: flex;
    align-items: center;
    gap: 10px;
    margin: 0 0 5px;
}

.fr-page-title>i {
    color: var(--fc-teal);
}

.fr-breadcrumb {
    display: flex;
    gap: 0;
    list-style: none;
    margin: 0;
    padding: 0;
    font-size: 12.5px;
    color: var(--fc-muted);
}

.fr-breadcrumb li+li::before {
    content: ' / ';
    margin: 0 4px;
    color: var(--fc-border);
}

.fr-breadcrumb a {
    color: var(--fc-teal);
    text-decoration: none;
    font-weight: 500;
}

.fr-topbar-right {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
}

/* ── Stat strip ── */
.fr-stat-strip {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 12px;
    margin-bottom: 20px;
}

@media(max-width:900px) {
    .fr-stat-strip {
        grid-template-columns: repeat(2, 1fr);
    }
}

@media(max-width:500px) {
    .fr-stat-strip {
        grid-template-columns: 1fr 1fr;
    }
}

.fr-stat {
    background: var(--fc-white);
    border-radius: var(--fc-radius);
    box-shadow: var(--fc-shadow);
    border: 1px solid var(--fc-border);
    padding: 14px 16px;
    display: flex;
    align-items: center;
    gap: 13px;
    transition: transform .15s, box-shadow .15s;
}

.fr-stat:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 20px rgba(11, 31, 58, .13);
}

.fr-stat-icon {
    width: 44px;
    height: 44px;
    border-radius: 10px;
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 18px;
}

.fr-stat-blue .fr-stat-icon {
    background: #dbeafe;
    color: var(--fc-blue);
}

.fr-stat-green .fr-stat-icon {
    background: #dcfce7;
    color: var(--fc-green);
}

.fr-stat-amber .fr-stat-icon {
    background: #fef3c7;
    color: var(--fc-amber);
}

.fr-stat-red .fr-stat-icon {
    background: #fee2e2;
    color: var(--fc-red);
}

.fr-stat-label {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .5px;
    text-transform: uppercase;
    color: var(--fc-muted);
    margin-bottom: 3px;
}

.fr-stat-val {
    font-family: 'Playfair Display', serif;
    font-size: 20px;
    font-weight: 700;
    color: var(--fc-navy);
    line-height: 1.15;
}

.fr-stat-val-sm {
    font-size: 15px !important;
}

.fr-stat-sub {
    font-size: 12.5px;
    color: var(--fc-muted);
    margin-top: 2px;
}

/* ── Card ── */
.fr-card {
    background: var(--fc-white);
    border-radius: var(--fc-radius);
    box-shadow: var(--fc-shadow);
    border: 1px solid var(--fc-border);
    overflow: hidden;
}

.fr-card-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    padding: 13px 18px;
    border-bottom: 1px solid var(--fc-border);
    background: linear-gradient(90deg, var(--fc-sky) 0%, var(--fc-white) 65%);
}

.fr-card-head-left {
    display: flex;
    align-items: center;
    gap: 9px;
    flex-wrap: wrap;
}

.fr-card-head-left>i {
    color: var(--fc-teal);
}

.fr-card-head-left>h3 {
    font-family: 'Playfair Display', serif;
    font-size: 15px;
    font-weight: 700;
    color: var(--fc-navy);
    margin: 0;
}

.fr-head-hint {
    font-size: 12.5px;
    color: var(--fc-muted);
    background: rgba(14, 116, 144, .07);
    border-radius: 20px;
    padding: 3px 10px;
}

.fr-card-head-right {
    display: flex;
    gap: 8px;
    align-items: center;
}

/* ── Filter bar ── */
.fr-filter-bar {
    display: flex;
    align-items: center;
    justify-content: space-between;
    flex-wrap: wrap;
    gap: 10px;
    padding: 10px 18px;
    border-bottom: 1px solid var(--fc-border);
    background: #fafcff;
}

.fr-search-wrap {
    position: relative;
    flex: 1;
    max-width: 280px;
}

.fr-search-icon {
    position: absolute;
    left: 10px;
    top: 50%;
    transform: translateY(-50%);
    color: var(--fc-muted);
    font-size: 12px;
    pointer-events: none;
}

.fr-search {
    width: 100%;
    height: 36px;
    padding: 0 10px 0 30px;
    border: 1.5px solid var(--fc-border);
    border-radius: 8px;
    font-size: 13px;
    font-family: 'DM Sans', sans-serif;
    outline: none;
    background: #fff;
    color: var(--fc-text);
    transition: border-color .13s, box-shadow .13s;
}

.fr-search:focus {
    border-color: var(--fc-teal);
    box-shadow: 0 0 0 3px rgba(14, 116, 144, .1);
}

.fr-row-count {
    font-size: 12px;
    color: var(--fc-muted);
    font-weight: 600;
    white-space: nowrap;
}

/* ── Table ── */
.fr-table-wrap {
    overflow-x: auto;
    max-height: 560px;
    overflow-y: auto;
}

.fr-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.fr-table thead th {
    background: var(--fc-navy);
    color: rgba(255, 255, 255, .88);
    padding: 10px 12px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: .4px;
    text-transform: uppercase;
    white-space: nowrap;
    text-align: center;
    position: sticky;
    top: 0;
    z-index: 4;
}

.fr-th-class {
    text-align: left !important;
    min-width: 155px;
    padding-left: 18px !important;
}

.fr-th-total {
    background: rgba(14, 116, 144, .5) !important;
    min-width: 108px;
}

.fr-table td {
    padding: 9px 12px;
    border-bottom: 1px solid var(--fc-border);
    text-align: center;
    vertical-align: middle;
    font-variant-numeric: tabular-nums;
}

.fr-td-class {
    text-align: left !important;
    font-weight: 600;
    white-space: nowrap;
    padding-left: 18px !important;
}

.fr-td-total {
    font-weight: 700;
    color: var(--fc-teal);
    background: rgba(14, 116, 144, .05);
    border-left: 2px solid rgba(14, 116, 144, .18);
    white-space: nowrap;
}

/* Class cell */
.fr-class-cell {
    display: flex;
    align-items: center;
    gap: 9px;
}

.fr-class-dot {
    width: 8px;
    height: 8px;
    border-radius: 50%;
    background: var(--fc-teal);
    flex-shrink: 0;
    transition: transform .15s, background .15s;
}

/* Heat-map */
.fr-cell-zero {
    color: var(--fc-border);
}

.fr-cell-low {
    /* default */
}

.fr-cell-mid {
    background: rgba(14, 116, 144, .07);
    font-weight: 500;
}

.fr-cell-high {
    background: rgba(22, 163, 74, .09);
    font-weight: 600;
    color: var(--fc-green);
}

/* Row states */
.fr-row {
    cursor: pointer;
    transition: background .1s;
}

.fr-row:hover {
    background: var(--fc-sky);
}

.fr-row:hover .fr-class-dot {
    transform: scale(1.5);
    background: var(--fc-amber);
}

.fr-row.fr-selected {
    background: rgba(14, 116, 144, .12) !important;
}

.fr-row.fr-selected .fr-class-dot {
    background: var(--fc-amber);
    transform: scale(1.5);
}

/* Tfoot */
.fr-table tfoot td {
    background: var(--fc-navy);
    color: rgba(255, 255, 255, .9);
    font-weight: 700;
    font-size: 12.5px;
    padding: 10px 12px;
    text-align: center;
    position: sticky;
    bottom: 0;
    z-index: 3;
}

.fr-table tfoot .fr-td-class {
    text-align: left !important;
    padding-left: 18px !important;
    color: #fff;
}

.fr-table tfoot .fr-td-total {
    background: rgba(14, 116, 144, .45);
    color: #5eead4;
}

/* ── Buttons ── */
.fr-btn {
    display: inline-flex;
    align-items: center;
    gap: 7px;
    padding: 8px 16px;
    border-radius: 8px;
    border: none;
    font-family: 'DM Sans', sans-serif;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    white-space: nowrap;
    text-decoration: none;
    transition: opacity .13s, transform .1s;
}

.fr-btn:hover:not(:disabled) {
    opacity: .85;
    transform: translateY(-1px);
}

.fr-btn:disabled {
    opacity: .38;
    cursor: not-allowed;
    transform: none !important;
}

.fr-btn-sm {
    padding: 6px 12px;
    font-size: 12px;
}

.fr-btn-primary {
    background: var(--fc-teal);
    color: #fff;
    box-shadow: 0 2px 8px rgba(14, 116, 144, .3);
}

.fr-btn-navy {
    background: var(--fc-navy);
    color: #fff;
}

.fr-btn-ghost {
    background: var(--fc-white);
    color: var(--fc-text);
    border: 1.5px solid var(--fc-border);
}

.fr-btn-ghost:hover:not(:disabled) {
    border-color: var(--fc-teal);
    color: var(--fc-teal);
    opacity: 1;
}

/* ── Empty ── */
.fr-empty {
    text-align: center;
    padding: 60px 20px;
    color: var(--fc-muted);
}

.fr-empty i {
    font-size: 48px;
    opacity: .2;
    display: block;
    margin-bottom: 14px;
}

.fr-empty p {
    margin: 0 0 5px;
    font-size: 14px;
}

.fr-empty-sub {
    font-size: 12.5px;
    opacity: .7;
}
</style>
