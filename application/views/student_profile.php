<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<?php
/* ══════════════════════════════════════════════════════════════
   SAFE HELPERS — works for BOTH student formats:
   NEW:    Doc[label] = ['url' => '...', 'thumbnail' => '...']
   LEGACY: Doc[label] = 'https://...'  (flat string)
   Very old: Doc['PhotoUrl'] = 'https://...'
══════════════════════════════════════════════════════════════ */
function sp_doc_url($entry)
{
    if (is_array($entry))  return $entry['url'] ?? '';
    if (is_string($entry)) return $entry;
    return '';
}

/* Resolve profile photo — check 3 possible locations */
$profilePhoto = '';
if (!empty($student['Profile Pic']) && is_string($student['Profile Pic'])) {
    $profilePhoto = $student['Profile Pic'];
} elseif (!empty($student['Doc']['Photo'])) {
    $profilePhoto = sp_doc_url($student['Doc']['Photo']);
} elseif (!empty($student['Doc']['PhotoUrl']) && is_string($student['Doc']['PhotoUrl'])) {
    $profilePhoto = $student['Doc']['PhotoUrl'];
}
$fallbackPhoto = base_url('tools/image/default-school.jpeg');

/* Build safe doc list — skip internal photo keys */
$skipKeys   = ['Photo', 'PhotoUrl'];
$docDisplay = [];
if (!empty($student['Doc']) && is_array($student['Doc'])) {
    foreach ($student['Doc'] as $label => $entry) {
        if (in_array($label, $skipKeys, true)) continue;
        $url   = sp_doc_url($entry);
        $thumb = is_array($entry) ? ($entry['thumbnail'] ?? '') : '';
        if ($url !== '') $docDisplay[$label] = ['url' => $url, 'thumbnail' => $thumb];
    }
}
?>
<div class="content-wrapper">
    <div class="sp-wrap">

        <div class="sp-heading"><i class="fa fa-id-card-o"></i> Student Profile</div>

        <!-- HERO -->
        <div class="sp-hero">
            <img class="sp-avatar" src="<?= htmlspecialchars($profilePhoto ?: $fallbackPhoto) ?>" alt="Photo"
                onerror="this.src='<?= htmlspecialchars($fallbackPhoto, ENT_QUOTES, 'UTF-8') ?>'">

            <div class="sp-hero-info">
                <h1 class="sp-hero-name"><?= htmlspecialchars($student['Name'] ?? 'Unknown') ?></h1>
                <p class="sp-hero-sub">
                    Class <?= htmlspecialchars($class ?? 'N/A') ?> &bull;
                    Section <?= htmlspecialchars($section ?? 'N/A') ?>
                </p>
                <div class="sp-badges">
                    <span class="sp-badge sp-badge-gold"><?= htmlspecialchars($student['User Id'] ?? '') ?></span>
                    <span class="sp-badge"><i
                            class="fa fa-calendar"></i>&nbsp;<?= htmlspecialchars($student['Admission Date'] ?? 'N/A') ?></span>
                    <span class="sp-badge"><i
                            class="fa fa-tint"></i>&nbsp;<?= htmlspecialchars($student['Blood Group'] ?? 'N/A') ?></span>
                    <span class="sp-badge"><i
                            class="fa fa-venus-mars"></i>&nbsp;<?= htmlspecialchars($student['Gender'] ?? 'N/A') ?></span>
                </div>
            </div>

            <div class="sp-hero-btns">
                <button class="sp-btn sp-btn-blue" id="viewFeesBtn"
                    data-user-id="<?= htmlspecialchars($student['User Id'] ?? '') ?>">
                    <i class="fa fa-rupee"></i> Submitted Fees
                </button>
                <a class="sp-btn sp-btn-ghost"
                    href="<?= base_url('student/edit_student/' . urlencode($student['User Id'] ?? '')) ?>">
                    <i class="fa fa-pencil"></i> Edit Student
                </a>
                <a class="sp-btn sp-btn-ghost"
                    href="<?= base_url('sis/history/' . urlencode($student['User Id'] ?? '')) ?>">
                    <i class="fa fa-history"></i> History
                </a>
            </div>
        </div>

        <!-- TABS -->
        <div class="sp-tabs">
            <button class="sp-tab is-active" data-tab="personal"><i class="fa fa-user"></i> Personal</button>
            <button class="sp-tab" data-tab="academic"><i class="fa fa-book"></i> Academic</button>
            <button class="sp-tab" data-tab="guardian"><i class="fa fa-users"></i> Guardian</button>
            <button class="sp-tab" data-tab="address"><i class="fa fa-map-marker"></i> Address</button>
            <button class="sp-tab" data-tab="previous"><i class="fa fa-university"></i> Prev. School</button>
            <button class="sp-tab" data-tab="fees"><i class="fa fa-money"></i> Fees</button>
            <button class="sp-tab" data-tab="documents"><i class="fa fa-file-text-o"></i> Documents</button>
        </div>

        <!-- PERSONAL -->
        <div class="sp-panel is-active" id="tab-personal">
            <div class="sp-card">
                <div class="sp-card-head"><i class="fa fa-user"></i>
                    <h3>Personal Details</h3>
                </div>
                <div class="sp-card-body">
                    <div class="sp-grid">
                        <?php foreach (
                            [
                                'Full Name'     => $student['Name']        ?? 'N/A',
                                'Date of Birth' => $student['DOB']         ?? 'N/A',
                                'Gender'        => $student['Gender']       ?? 'N/A',
                                'Blood Group'   => $student['Blood Group']  ?? 'N/A',
                                'Category'      => $student['Category']     ?? 'N/A',
                                'Religion'      => $student['Religion']     ?? 'N/A',
                                'Nationality'   => $student['Nationality']  ?? 'N/A',
                                'Email'         => $student['Email']        ?? 'N/A',
                                'Phone Number'  => $student['Phone Number'] ?? 'N/A',
                            ] as $lbl => $val
                        ): ?>
                        <div>
                            <div class="sp-field-label"><?= $lbl ?></div>
                            <div class="sp-field-value"><?= htmlspecialchars((string)$val) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ACADEMIC -->
        <div class="sp-panel" id="tab-academic">
            <div class="sp-card">
                <div class="sp-card-head"><i class="fa fa-book"></i>
                    <h3>Academic Details</h3>
                </div>
                <div class="sp-card-body">
                    <div class="sp-grid" style="margin-bottom:18px;">
                        <div>
                            <div class="sp-field-label">Class</div>
                            <div class="sp-field-value"><?= htmlspecialchars($class ?? 'N/A') ?></div>
                        </div>
                        <div>
                            <div class="sp-field-label">Section</div>
                            <div class="sp-field-value"><?= htmlspecialchars($section ?? 'N/A') ?></div>
                        </div>
                    </div>
                    <div class="sp-field-label" style="margin-bottom:8px;">Subjects</div>
                    <div class="sp-chips">
                        <?php if (!empty($subjects)):
                            foreach ($subjects as $sub): ?>
                        <span class="sp-chip"><?= htmlspecialchars($sub) ?></span>
                        <?php endforeach;
                        else: ?>
                        <span style="color:var(--sp-muted);font-size:13px;">No subjects found.</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- GUARDIAN -->
        <div class="sp-panel" id="tab-guardian">
            <div class="sp-card">
                <div class="sp-card-head"><i class="fa fa-users"></i>
                    <h3>Guardian Details</h3>
                </div>
                <div class="sp-card-body">
                    <div class="sp-grid">
                        <?php foreach (
                            [
                                "Father's Name"       => $student['Father Name']      ?? 'N/A',
                                "Father's Occupation" => $student['Father Occupation'] ?? 'N/A',
                                "Mother's Name"       => $student['Mother Name']       ?? 'N/A',
                                "Mother's Occupation" => $student['Mother Occupation'] ?? 'N/A',
                                'Guardian Relation'   => $student['Guard Relation']    ?? 'N/A',
                                'Guardian Contact'    => $student['Guard Contact']     ?? 'N/A',
                            ] as $lbl => $val
                        ): ?>
                        <div>
                            <div class="sp-field-label"><?= $lbl ?></div>
                            <div class="sp-field-value"><?= htmlspecialchars((string)$val) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- ADDRESS -->
        <div class="sp-panel" id="tab-address">
            <div class="sp-card">
                <div class="sp-card-head"><i class="fa fa-map-marker"></i>
                    <h3>Address Details</h3>
                </div>
                <div class="sp-card-body">
                    <div class="sp-grid">
                        <?php foreach (
                            [
                                'Street'      => $student['Address']['Street']     ?? 'N/A',
                                'City'        => $student['Address']['City']       ?? 'N/A',
                                'State'       => $student['Address']['State']      ?? 'N/A',
                                'Postal Code' => $student['Address']['PostalCode'] ?? 'N/A',
                            ] as $lbl => $val
                        ): ?>
                        <div>
                            <div class="sp-field-label"><?= $lbl ?></div>
                            <div class="sp-field-value"><?= htmlspecialchars((string)$val) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- PREVIOUS SCHOOL -->
        <div class="sp-panel" id="tab-previous">
            <div class="sp-card">
                <div class="sp-card-head"><i class="fa fa-university"></i>
                    <h3>Previous School Details</h3>
                </div>
                <div class="sp-card-body">
                    <div class="sp-grid">
                        <?php foreach (
                            [
                                'Previous School' => $student['Pre School'] ?? 'N/A',
                                'Previous Class Completed' => $student['Pre Class']  ?? 'N/A',
                                'Previous Marks% Obtained'  => $student['Pre Marks']  ?? 'N/A',
                            ] as $lbl => $val
                        ): ?>
                        <div>
                            <div class="sp-field-label"><?= $lbl ?></div>
                            <div class="sp-field-value"><?= htmlspecialchars((string)$val) ?></div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- FEES -->
        <div class="sp-panel" id="tab-fees">
            <?php if (!empty($fees)):
                $yearlyTotal = 0;
                $mGrand = 0;

                // Collect monthly fee titles
                $mTitles = [];
                foreach ($fees as $mon => $fd) {
                    if ($mon === 'Yearly Fees' || !is_array($fd)) continue;
                    foreach ($fd as $ft => $a) $mTitles[$ft] = true;
                }
                $mTitles = array_keys($mTitles);
            ?>

            <!-- Yearly fees card -->
            <div class="sp-card">
                <div class="sp-card-head"><i class="fa fa-calendar-o"></i>
                    <h3>Yearly Fees</h3>
                </div>
                <div class="sp-card-body">
                    <?php if (!empty($fees['Yearly Fees'])): ?>
                    <div class="sp-tbl-wrap">
                        <table class="sp-tbl">
                            <thead>
                                <tr>
                                    <th>Fee Title</th>
                                    <th>Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($fees['Yearly Fees'] as $title => $amt): $yearlyTotal += (float)$amt; ?>
                                <tr>
                                    <td><?= htmlspecialchars($title) ?></td>
                                    <td>₹<?= number_format((float)$amt, 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td><strong>Total</strong></td>
                                    <td><strong>₹<?= number_format($yearlyTotal, 2) ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <?php else: ?>
                    <p style="color:var(--sp-muted);text-align:center;padding:20px;">No yearly fees configured.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Monthly summary card -->
            <div class="sp-card">
                <div class="sp-card-head"><i class="fa fa-table"></i>
                    <h3>Monthly Fees Summary</h3>
                </div>
                <div class="sp-card-body">
                    <?php if (!empty($mTitles)): ?>
                    <div class="sp-tbl-wrap">
                        <table class="sp-tbl">
                            <thead>
                                <tr>
                                    <th>Fee Title</th>
                                    <th>Annual Total</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($mTitles as $ft):
                                            $rt = 0;
                                            foreach ($fees as $mon => $fd) {
                                                if ($mon === 'Yearly Fees' || !is_array($fd)) continue;
                                                $rt += (float)($fd[$ft] ?? 0);
                                            }
                                            $mGrand += $rt;
                                        ?>
                                <tr>
                                    <td><?= htmlspecialchars($ft) ?></td>
                                    <td>₹<?= number_format($rt, 2) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <td><strong>Grand Total</strong></td>
                                    <td><strong>₹<?= number_format($mGrand, 2) ?></strong></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                    <div style="text-align:right;margin-top:12px;">
                        <button class="sp-btn sp-btn-blue" id="openFeeModal">
                            <i class="fa fa-expand"></i> Month-wise Breakdown
                        </button>
                    </div>
                    <?php else: ?>
                    <p style="color:var(--sp-muted);text-align:center;padding:20px;">No monthly fees configured.</p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Discount card -->
            <div class="sp-card">
                <div class="sp-card-head"><i class="fa fa-tag"></i>
                    <h3>Discount</h3>
                </div>
                <div class="sp-card-body">
                    <div class="sp-grid" style="margin-bottom:18px;">
                        <div>
                            <div class="sp-field-label">Current Discount</div>
                            <div class="sp-field-value">
                                ₹<?= isset($currentdiscount) && $currentdiscount !== '' ? number_format((float)$currentdiscount, 2) : '0.00' ?>
                            </div>
                        </div>
                        <div>
                            <div class="sp-field-label">Total Discount Given</div>
                            <div class="sp-field-value">
                                ₹<?= isset($totaldiscount) && $totaldiscount !== '' ? number_format((float)$totaldiscount, 2) : '0.00' ?>
                            </div>
                        </div>
                    </div>
                    <div class="sp-field-label" style="margin-bottom:7px;">Add On-Demand Discount</div>
                    <form id="onDemandDiscountForm">
                        <div class="sp-disc-row">
                            <input type="number" id="onDemandDiscount" name="onDemandDiscount"
                                placeholder="Enter amount in ₹" min="0" required>
                            <button type="submit" class="sp-btn sp-btn-green" id="submitDiscountButton">
                                <i class="fa fa-check"></i> Apply Discount
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Overall total -->
            <?php $overallTot = $yearlyTotal + $mGrand - (float)($totaldiscount ?? 0); ?>
            <div class="sp-total">
                <div class="sp-total-cell">
                    <div class="sp-total-label">Yearly Fees</div>
                    <div class="sp-total-val">₹<?= number_format($yearlyTotal, 2) ?></div>
                </div>
                <div class="sp-total-sep">+</div>
                <div class="sp-total-cell">
                    <div class="sp-total-label">Monthly Fees</div>
                    <div class="sp-total-val">₹<?= number_format($mGrand, 2) ?></div>
                </div>
                <div class="sp-total-sep">&minus;</div>
                <div class="sp-total-cell">
                    <div class="sp-total-label">Discount</div>
                    <div class="sp-total-val sp-total-dis">₹<?= number_format((float)($totaldiscount ?? 0), 2) ?></div>
                </div>
                <div class="sp-total-eq">=</div>
                <div class="sp-total-cell sp-total-cell--grand">
                    <div class="sp-total-label">Grand Total</div>
                    <div class="sp-total-val sp-total-grand">₹<?= number_format($overallTot, 2) ?></div>
                </div>
            </div>

            <?php else: ?>
            <div class="sp-card">
                <div class="sp-card-body" style="text-align:center;padding:40px;color:var(--sp-muted);">
                    <i class="fa fa-info-circle" style="font-size:30px;display:block;margin-bottom:10px;"></i>
                    No fee data found for this student's class.
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- DOCUMENTS — THE CRASH FIX IS HERE -->
        <!-- sp_doc_url() always returns a string, never an array -->
        <!-- so htmlspecialchars() never receives an array = no fatal error -->
        <div class="sp-panel" id="tab-documents">
            <div class="sp-card">
                <div class="sp-card-head"><i class="fa fa-file-text-o"></i>
                    <h3>Documents</h3>
                </div>
                <div class="sp-card-body">
                    <?php if (!empty($docDisplay)): ?>
                    <div class="sp-doc-grid">
                        <?php foreach ($docDisplay as $label => $item):
                                $url   = $item['url'];
                                $thumb = $item['thumbnail'];
                                $urlPath = rawurldecode(parse_url($url, PHP_URL_PATH) ?? '');
                                $ext   = strtolower(pathinfo($urlPath, PATHINFO_EXTENSION));
                                $isPdf = ($ext === 'pdf');
                                $isImg = in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true);
                            ?>
                        <div class="sp-doc-card">
                            <?php if ($isImg && $thumb !== ''): ?>
                            <div class="sp-doc-thumb">
                                <img src="<?= htmlspecialchars($thumb) ?>" alt="<?= htmlspecialchars($label) ?>"
                                    onerror="this.parentElement.innerHTML='<i class=\'fa fa-file-image-o\' style=\'font-size:28px;color:var(--gold)\'></i>'">
                            </div>
                            <?php elseif ($isPdf): ?>
                            <div class="sp-doc-icon sp-doc-icon--pdf"><i class="fa fa-file-pdf-o"></i></div>
                            <?php else: ?>
                            <div class="sp-doc-icon"><i class="fa fa-file-image-o"></i></div>
                            <?php endif; ?>
                            <div class="sp-doc-name"><?= htmlspecialchars($label) ?></div>
                            <a href="<?= htmlspecialchars($url) ?>" target="_blank" rel="noopener noreferrer" class="sp-doc-link">
                                <i class="fa fa-eye"></i> View
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php else: ?>
                    <div style="text-align:center;padding:36px;color:var(--sp-muted);">
                        <i class="fa fa-folder-open-o" style="font-size:30px;display:block;margin-bottom:10px;"></i>
                        No documents uploaded.
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

    </div><!-- /.sp-wrap -->
</div><!-- /.content-wrapper -->


<!-- FEE MODAL -->
<div class="sp-overlay" id="feeOverlay">
    <div class="sp-modal">
        <div class="sp-modal-head">
            <h4><i class="fa fa-table" style="color:var(--sp-blue);margin-right:7px;"></i>Month-wise Fee Breakdown</h4>
            <button class="sp-modal-close" id="closeFeeModal">&times;</button>
        </div>
        <div class="sp-modal-body">
            <?php if (!empty($fees)):
                $monthOrder   = ['April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December', 'January', 'February', 'March'];
                $sortedMonths = array_values(array_intersect($monthOrder, array_keys($fees)));
                $mColTitles   = [];
                foreach ($fees as $mon => $fd) {
                    if ($mon === 'Yearly Fees' || !is_array($fd)) continue;
                    foreach ($fd as $ft => $a) $mColTitles[$ft] = true;
                }
                $mColTitles = array_keys($mColTitles);
                $colTotals  = array_fill_keys($sortedMonths, 0);
                $modalGrand = 0;
            ?>
            <div class="sp-tbl-wrap">
                <table class="sp-tbl">
                    <thead>
                        <tr>
                            <th>Fee Type</th>
                            <?php foreach ($sortedMonths as $mon): ?><th><?= htmlspecialchars($mon) ?></th><?php endforeach; ?>
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mColTitles as $ft): $rowT = 0; ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($ft) ?></strong></td>
                            <?php foreach ($sortedMonths as $mon):
                                        $amt = (float)($fees[$mon][$ft] ?? 0);
                                        $colTotals[$mon] += $amt;
                                        $rowT += $amt;
                                    ?>
                            <td>₹<?= number_format($amt, 2) ?></td>
                            <?php endforeach; ?>
                            <td><strong>₹<?= number_format($rowT, 2) ?></strong></td>
                        </tr>
                        <?php $modalGrand += $rowT;
                            endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr>
                            <td><strong>Total</strong></td>
                            <?php foreach ($sortedMonths as $mon): ?>
                            <td><strong>₹<?= number_format($colTotals[$mon], 2) ?></strong></td>
                            <?php endforeach; ?>
                            <td><strong>₹<?= number_format($modalGrand, 2) ?></strong></td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- ── Exempted Fees (restored from original code) ── -->
            <?php
                /* $exempted_fees is passed from student_profile() controller.
               It is an associative array like ['Bus Fees' => '', 'Tuition Fee' => '']
               array_keys() extracts the fee names for display. */
                $exemptedList = [];
                if (!empty($exempted_fees) && is_array($exempted_fees)) {
                    $exemptedList = array_keys($exempted_fees);
                }
                ?>
            <div style="margin-top:28px;padding-top:20px;border-top:1px solid var(--sp-border);">
                <h5
                    style="font-family:var(--font-d);font-size:16px;font-weight:700;color:var(--t1);margin-bottom:12px;text-align:center;">
                    <i class="fa fa-ban" style="color:#dc2626;margin-right:6px;"></i>
                    Exempted Fees For This Student
                </h5>
                <?php if (!empty($exemptedList)): ?>
                <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:center;">
                    <?php foreach ($exemptedList as $fee): ?>
                    <span style="
                                background:#fee2e2;
                                color:#991b1b;
                                border:1px solid #fca5a5;
                                border-radius:20px;
                                padding:5px 16px;
                                font-size:13px;
                                font-weight:600;
                                font-family:'Plus Jakarta Sans',sans-serif;
                                display:inline-flex;
                                align-items:center;
                                gap:5px;
                            ">
                        <i class="fa fa-check-circle"></i>
                        <?= htmlspecialchars($fee) ?>
                    </span>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <p style="text-align:center;color:var(--sp-muted);font-size:13px;">
                    <i class="fa fa-info-circle"></i>&nbsp;No fees exempted for this student.
                </p>
                <?php endif; ?>
            </div>

            <?php endif; ?>
        </div>
    </div>
</div>


<script>
(function () {
    'use strict';

    /* Tab switching */
    document.querySelectorAll('.sp-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.sp-tab').forEach(function (t)   { t.classList.remove('is-active'); });
            document.querySelectorAll('.sp-panel').forEach(function (p) { p.classList.remove('is-active'); });
            this.classList.add('is-active');
            var panel = document.getElementById('tab-' + this.dataset.tab);
            if (panel) panel.classList.add('is-active');
        });
    });

    /* Fee modal */
    var overlay  = document.getElementById('feeOverlay');
    var openBtn  = document.getElementById('openFeeModal');
    var closeBtn = document.getElementById('closeFeeModal');
    if (openBtn)  openBtn.addEventListener('click',  function () { overlay.classList.add('open'); });
    if (closeBtn) closeBtn.addEventListener('click', function () { overlay.classList.remove('open'); });
    if (overlay)  overlay.addEventListener('click',  function (e) { if (e.target === overlay) overlay.classList.remove('open'); });

    /* View submitted fees */
    var feesBtn = document.getElementById('viewFeesBtn');
    if (feesBtn) {
        feesBtn.addEventListener('click', function () {
            var uid = this.dataset.userId;
            if (uid) window.location.href = '<?= base_url("fees/student_fees?userId=") ?>' + encodeURIComponent(uid);
        });
    }

    /* On-demand discount */
    var discForm = document.getElementById('onDemandDiscountForm');
    var discBtn  = document.getElementById('submitDiscountButton');
    if (discForm && discBtn) {
        discForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var val = document.getElementById('onDemandDiscount').value.trim();
            if (!val) { alert('Please enter a discount amount.'); return; }

            discBtn.disabled = true;
            discBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Applying...';

            // FIXED: use global csrfName/csrfToken from header meta tags instead of hardcoded PHP values
            var payload = new URLSearchParams();
            payload.append(csrfName, csrfToken);
            payload.append('userId',  '<?= htmlspecialchars($student['User Id'] ?? '', ENT_QUOTES) ?>');
            payload.append('class',   '<?= htmlspecialchars($class ?? '', ENT_QUOTES) ?>');
            payload.append('section', '<?= htmlspecialchars($section ?? '', ENT_QUOTES) ?>');
            payload.append('discount', val);

            fetch('<?= base_url("fees/submit_discount") ?>', {
                method:  'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
                body:    payload
            })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.status === 'success') { alert('Discount applied!'); window.location.reload(); }
                else { alert('Error: ' + (data.message || 'Unknown error')); }
            })
            .catch(function () { alert('Failed to apply discount.'); })
            .finally(function () {
                discBtn.disabled = false;
                discBtn.innerHTML = '<i class="fa fa-check"></i> Apply Discount';
            });
        });
    }

})();
</script>

<style>
/* ── Student Profile — ERP Gold Theme (day/night aware) ── */

/* Map legacy --sp-* vars → ERP theme vars so inline HTML styles keep working */
:root, [data-theme="night"], [data-theme="day"] {
    --sp-muted:  var(--t3);
    --sp-border: var(--border);
    --sp-text:   var(--t1);
    --sp-white:  var(--bg2);
    --sp-bg:     var(--bg);
    --sp-shadow: var(--sh);
    --sp-radius: 14px;
    --sp-blue:   var(--gold);
    --sp-sky:    var(--gold-dim);
    --sp-gold:   var(--gold);
    --sp-navy:   var(--t1);
    --sp-green:  var(--green, #3DD68C);
    --sp-red:    var(--rose, #E05C6F);
}

/* ── Page wrap ── */
.sp-wrap {
    font-family: var(--font-b);
    background: var(--bg);
    color: var(--t1);
    padding: 24px 20px 52px;
    min-height: 100vh;
}

.sp-heading {
    font-family: var(--font-d);
    font-size: 22px;
    font-weight: 800;
    color: var(--t1);
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 22px;
}
.sp-heading i { color: var(--gold); }

/* ── Hero — always dark with gold accent ── */
.sp-hero {
    background: linear-gradient(130deg, #0c1e38 0%, #070f1c 100%);
    border: 1px solid rgba(15,118,110,.20);
    border-radius: var(--r, 14px);
    padding: 28px 32px;
    display: flex;
    align-items: center;
    gap: 26px;
    margin-bottom: 20px;
    box-shadow: 0 4px 32px rgba(0,0,0,.45);
    position: relative;
    overflow: hidden;
    flex-wrap: wrap;
}
.sp-hero::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
    background: linear-gradient(90deg, var(--gold) 0%, rgba(15,118,110,.1) 100%);
}
.sp-hero::after {
    content: '';
    position: absolute;
    right: -60px; top: -60px;
    width: 240px; height: 240px;
    border-radius: 50%;
    background: rgba(15,118,110,.04);
    pointer-events: none;
}

.sp-avatar {
    width: 100px; height: 100px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid var(--gold);
    box-shadow: 0 4px 18px rgba(0,0,0,.4), 0 0 0 5px rgba(15,118,110,.12);
    flex-shrink: 0;
    position: relative;
    z-index: 1;
}

.sp-hero-info { position: relative; z-index: 1; flex: 1; }

.sp-hero-name {
    font-family: var(--font-b);
    font-size: 22px;
    font-weight: 700;
    color: #F0E8D5;
    margin: 0 0 4px;
    letter-spacing: -.2px;
}
.sp-hero-sub { color: rgba(148,201,195,.6); font-size: 13px; margin: 0 0 12px; font-family: var(--font-b); }

.sp-badges { display: flex; flex-wrap: wrap; gap: 8px; }

.sp-badge {
    padding: 3px 13px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: 500;
    background: rgba(255,255,255,.07);
    color: #94c9c3;
    border: 1px solid rgba(15,118,110,.20);
    font-family: var(--font-b);
}
.sp-badge-gold {
    background: var(--gold);
    color: #ffffff;
    border-color: var(--gold);
    font-weight: 700;
    font-family: var(--font-m);
}

.sp-hero-btns {
    position: relative; z-index: 1;
    display: flex;
    flex-direction: column;
    gap: 8px;
    align-items: flex-end;
}

/* ── Buttons ── */
.sp-btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 18px;
    border-radius: var(--r-sm, 8px);
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: all var(--ease);
    white-space: nowrap;
    font-family: var(--font-b);
}
.sp-btn:hover { transform: translateY(-1px); text-decoration: none; }

.sp-btn-blue  { background: var(--gold); color: #ffffff; }
.sp-btn-blue:hover  { background: var(--gold2, #0d6b63); box-shadow: 0 4px 14px rgba(15,118,110,.4); color: #ffffff; }

.sp-btn-green { background: var(--green, #3DD68C); color: #ffffff; }
.sp-btn-green:hover { opacity: .88; }

.sp-btn-ghost {
    background: rgba(255,255,255,.08);
    color: #94c9c3;
    border: 1px solid rgba(15,118,110,.25);
}
.sp-btn-ghost:hover { background: rgba(15,118,110,.12); color: var(--gold); border-color: var(--gold); }

/* ── Tabs ── */
.sp-tabs {
    display: flex;
    flex-wrap: wrap;
    gap: 5px;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--r, 14px);
    padding: 7px;
    box-shadow: var(--sh);
    margin-bottom: 18px;
}

.sp-tab {
    padding: 8px 16px;
    border-radius: var(--r-sm, 8px);
    font-size: 13px;
    font-weight: 600;
    color: var(--t3);
    cursor: pointer;
    border: none;
    background: transparent;
    transition: all var(--ease);
    display: flex;
    align-items: center;
    gap: 6px;
    font-family: var(--font-b);
}
.sp-tab:hover  { background: var(--gold-dim); color: var(--gold); }
.sp-tab.is-active {
    background: var(--gold);
    color: #ffffff;
    font-weight: 700;
    box-shadow: 0 2px 10px rgba(15,118,110,.3);
}

/* ── Panels ── */
.sp-panel { display: none; }
.sp-panel.is-active { display: block; }

/* ── Cards ── */
.sp-card {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--r, 14px);
    box-shadow: var(--sh);
    overflow: hidden;
    margin-bottom: 18px;
}
.sp-card-head {
    padding: 12px 20px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 9px;
    background: var(--bg3);
}
.sp-card-head h3 {
    margin: 0;
    font-family: var(--font-b);
    font-size: 12px;
    font-weight: 700;
    color: var(--t2);
    text-transform: uppercase;
    letter-spacing: .6px;
}
.sp-card-head i { color: var(--gold); }
.sp-card-body { padding: 20px 22px; }

/* ── Info grid ── */
.sp-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 14px;
}
.sp-field-label {
    font-size: 10.5px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .6px;
    color: var(--t3);
    margin-bottom: 3px;
    font-family: var(--font-m);
}
.sp-field-value {
    font-size: 14px;
    font-weight: 600;
    color: var(--t1);
    font-family: var(--font-b);
}

/* ── Subject chips ── */
.sp-chips { display: flex; flex-wrap: wrap; gap: 7px; }
.sp-chip {
    background: var(--gold-dim);
    color: var(--gold);
    border: 1px solid var(--gold-ring, rgba(15,118,110,.22));
    border-radius: 20px;
    padding: 4px 13px;
    font-size: 12px;
    font-weight: 600;
    font-family: var(--font-b);
}

/* ── Documents ── */
.sp-doc-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 14px;
}
.sp-doc-card {
    border: 1px solid var(--border);
    border-radius: var(--r, 12px);
    padding: 18px 14px;
    text-align: center;
    background: var(--bg3);
    transition: box-shadow var(--ease), transform var(--ease), border-color var(--ease);
}
.sp-doc-card:hover {
    box-shadow: 0 4px 18px rgba(15,118,110,.15);
    transform: translateY(-2px);
    border-color: var(--gold-ring, rgba(15,118,110,.22));
}
.sp-doc-icon {
    width: 46px; height: 46px;
    border-radius: 10px;
    background: var(--gold-dim);
    display: flex;
    align-items: center;
    justify-content: center;
    margin: 0 auto 10px;
    font-size: 20px;
    color: var(--gold);
}
.sp-doc-icon--pdf {
    background: rgba(220,38,38,.10);
    color: #dc2626;
}
.sp-doc-thumb {
    width: 60px; height: 60px;
    border-radius: 8px;
    overflow: hidden;
    margin: 0 auto 10px;
    border: 1px solid var(--border);
}
.sp-doc-thumb img {
    width: 100%; height: 100%;
    object-fit: cover;
    display: block;
}
.sp-doc-name {
    font-size: 13px;
    font-weight: 600;
    color: var(--t1);
    margin-bottom: 12px;
    font-family: var(--font-b);
}
.sp-doc-link {
    display: inline-flex;
    align-items: center;
    gap: 5px;
    padding: 6px 14px;
    border-radius: var(--r-sm, 7px);
    background: var(--gold);
    color: #ffffff;
    font-size: 12px;
    font-weight: 700;
    text-decoration: none;
    transition: all var(--ease);
    font-family: var(--font-b);
}
.sp-doc-link:hover { background: var(--gold2, #0d6b63); color: #ffffff; text-decoration: none; }

/* ── Tables ── */
.sp-tbl-wrap { overflow-x: auto; }
.sp-tbl {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
    font-family: var(--font-b);
}
.sp-tbl th {
    background: linear-gradient(90deg, var(--gold) 0%, var(--gold2, #0d6b63) 100%);
    color: #ffffff;
    padding: 10px 13px;
    text-align: left;
    font-weight: 700;
    white-space: nowrap;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: .4px;
}
.sp-tbl td {
    padding: 9px 13px;
    border-bottom: 1px solid var(--border);
    color: var(--t2);
}
.sp-tbl tr:hover td { background: var(--gold-dim); }
.sp-tbl tfoot td {
    background: var(--gold);
    color: #ffffff;
    font-weight: 700;
    border: none;
}

/* ── Fee summary strip ── */
.sp-total {
    display: flex;
    align-items: center;
    gap: 0;
    background: var(--bg2);
    border: 1px solid var(--border);
    border-top: 3px solid var(--gold);
    border-radius: var(--r, 12px);
    overflow: hidden;
    margin-top: 18px;
    box-shadow: var(--sh);
    flex-wrap: wrap;
}
.sp-total-cell {
    flex: 1;
    min-width: 120px;
    padding: 16px 20px;
    text-align: center;
    border-right: 1px solid var(--border);
}
.sp-total-cell:last-child { border-right: none; }
.sp-total-cell--grand {
    background: linear-gradient(135deg, #0c1e38 0%, #070f1c 100%);
    flex: 1.4;
}
.sp-total-label {
    font-size: 10px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: .7px;
    color: var(--t3);
    font-family: var(--font-m);
    margin-bottom: 5px;
}
.sp-total-cell--grand .sp-total-label { color: rgba(148,201,195,.5); }
.sp-total-val {
    font-size: 18px;
    font-weight: 700;
    color: var(--t1);
    font-family: var(--font-b);
}
.sp-total-dis { color: var(--rose, #E05C6F); }
.sp-total-grand {
    font-size: 22px;
    font-weight: 800;
    color: var(--gold);
    font-family: var(--font-b);
}
.sp-total-sep {
    font-size: 18px;
    color: var(--t3);
    font-weight: 700;
    padding: 0 2px;
    flex-shrink: 0;
    align-self: center;
}
.sp-total-eq {
    font-size: 22px;
    color: var(--gold);
    font-weight: 800;
    padding: 0 4px;
    flex-shrink: 0;
    align-self: center;
}

/* ── Discount ── */
.sp-disc-row {
    display: flex;
    gap: 10px;
    align-items: center;
    flex-wrap: wrap;
    margin-top: 10px;
}
.sp-disc-row input {
    flex: 1;
    min-width: 180px;
    padding: 8px 13px;
    border: 1.5px solid var(--brd2);
    border-radius: var(--r-sm, 8px);
    font-size: 13px;
    outline: none;
    background: var(--bg3);
    color: var(--t1);
    transition: border-color var(--ease), box-shadow var(--ease);
    font-family: var(--font-b);
}
.sp-disc-row input:focus {
    border-color: var(--gold);
    box-shadow: 0 0 0 3px rgba(15,118,110,.15);
}

/* ── Modal ── */
.sp-overlay {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,.6);
    z-index: 9100;
    align-items: center;
    justify-content: center;
    backdrop-filter: blur(2px);
}
.sp-overlay.open { display: flex; }

.sp-modal {
    background: var(--bg2);
    border: 1px solid var(--border);
    border-radius: var(--r, 14px);
    width: 96%;
    max-width: 1080px;
    max-height: 86vh;
    overflow-y: auto;
    box-shadow: 0 8px 40px rgba(0,0,0,.35);
}
.sp-modal-head {
    padding: 14px 22px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    background: var(--bg2);
    z-index: 1;
}
.sp-modal-head h4 {
    margin: 0;
    font-family: var(--font-d);
    font-size: 16px;
    font-weight: 700;
    color: var(--t1);
}
.sp-modal-close {
    background: none;
    border: none;
    font-size: 22px;
    line-height: 1;
    color: var(--t3);
    cursor: pointer;
    padding: 4px 8px;
    border-radius: 6px;
    transition: all var(--ease);
}
.sp-modal-close:hover { background: var(--gold-dim); color: var(--gold); }
.sp-modal-body { padding: 22px; }

/* ── Responsive ── */
@media (max-width: 720px) {
    .sp-hero { flex-direction: column; text-align: center; }
    .sp-hero-btns { align-items: center; }
    .sp-badges { justify-content: center; }
    .sp-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 480px) {
    .sp-grid { grid-template-columns: 1fr; }
    .sp-tab { padding: 7px 10px; font-size: 12px; }
}
</style>