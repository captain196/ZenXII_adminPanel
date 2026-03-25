<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<div class="content-wrapper">
    <div class="fm-wrap">
        <!-- Top bar -->
        <div class="fm-topbar">
            <h1 class="fm-page-title">
                <i class="fa fa-tags"></i> Fees Structure
            </h1>
            <ul class="fm-breadcrumb">
                <li><a href="<?= base_url() ?>">Dashboard</a></li>
                <li>Fees</li>
                <li>Fees Structure</li>
            </ul>
        </div>

        <!-- Stats -->
        <?php
        $monthly_count = 0;
        $yearly_count  = 0;
        if (!empty($feesStructure)) {
            if (isset($feesStructure['Monthly'])) $monthly_count = count($feesStructure['Monthly']);
            if (isset($feesStructure['Yearly']))  $yearly_count  = count($feesStructure['Yearly']);
        }
        ?>
        <div class="fm-stats">
            <div class="fm-stat teal">
                <div class="fm-stat-label">Monthly Fee Titles</div>
                <div class="fm-stat-value"><?= $monthly_count ?></div>
            </div>
            <div class="fm-stat gold">
                <div class="fm-stat-label">Yearly Fee Titles</div>
                <div class="fm-stat-value"><?= $yearly_count ?></div>
            </div>
            <div class="fm-stat">
                <div class="fm-stat-label">Total Titles</div>
                <div class="fm-stat-value"><?= $monthly_count + $yearly_count ?></div>
            </div>
        </div>

        <!-- Add Fee Title -->
        <div class="fm-card">
            <div class="fm-card-head">
                <i class="fa fa-plus-circle"></i>
                <h3>Add Fee Title</h3>
            </div>
            <div class="fm-card-body">
                <form id="add_fees_title">
                    <div class="fm-grid-form">
                        <div>
                            <label class="fm-label">Fee Title <span class="fm-req">*</span></label>
                            <input type="text" name="fee_title" id="fee_title"
                                class="fm-input" placeholder="e.g. Tuition Fee" required>
                        </div>
                        <div>
                            <label class="fm-label">Fee Type <span class="fm-req">*</span></label>
                            <select name="fee_type" id="fee_type" class="fm-select" required>
                                <option value="">Select Fee Type</option>
                                <option value="Monthly">Monthly</option>
                                <option value="Yearly">Yearly</option>
                            </select>
                        </div>
                        <div>
                            <button type="submit" class="fm-btn fm-btn-primary">
                                <i class="fa fa-save"></i> Save Title
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>

        <!-- Fee Titles Table -->
        <div class="fm-card">
            <div class="fm-card-head">
                <i class="fa fa-list"></i>
                <h3>Fee Titles List</h3>
            </div>
            <div class="fm-card-body" style="padding:0">
                <div class="fm-table-wrap">
                    <table class="fm-table">
                        <thead>
                            <tr>
                                <th style="width:60px">S.No.</th>
                                <th>Fee Title</th>
                                <th>Fee Type</th>
                                <th style="width:90px">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (!empty($feesStructure)) : ?>
                                <?php $sno = 1; ?>
                                <?php foreach ($feesStructure as $feeType => $feeTitles) : ?>
                                    <?php if (is_array($feeTitles)) : ?>
                                        <?php foreach ($feeTitles as $feeTitle => $value) : ?>
                                            <tr>
                                                <td class="fm-muted" style="color:var(--fm-muted)"><?= $sno++ ?></td>
                                                <td style="font-weight:600"><?= htmlspecialchars($feeTitle) ?></td>
                                                <td>
                                                    <span class="fm-badge <?= $feeType === 'Monthly' ? 'fm-badge-teal' : 'fm-badge-gold' ?>">
                                                        <i class="fa fa-<?= $feeType === 'Monthly' ? 'calendar' : 'star' ?>" style="font-size:10px"></i>
                                                        <?= htmlspecialchars($feeType) ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="<?= base_url('fees/delete_fees_structure/' . urlencode($feeTitle) . '/' . urlencode($feeType)) ?>"
                                                        class="fm-btn fm-btn-danger fm-btn-sm"
                                                        onclick="return confirm('Delete fee title: <?= addslashes($feeTitle) ?>?')">
                                                        <i class="fa fa-trash-o"></i>
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            <?php else : ?>
                                <tr>
                                    <td colspan="4">
                                        <div class="fm-empty">
                                            <i class="fa fa-inbox"></i>
                                            No fee titles added yet. Start by adding one above.
                                        </div>
                                    </td>
                                </tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Toast container -->
<div class="fm-toast-wrap" id="fmToastWrap"></div>

<script>
(function () {

    /* ── CSRF tokens from header meta tags (set in include/header.php) ── */
    var CSRF_NAME = document.querySelector('meta[name="csrf-name"]').getAttribute('content');
    var CSRF_HASH = document.querySelector('meta[name="csrf-token"]').getAttribute('content');

    /* ── Toast helper ─────────────────────────────────────────────────── */
    function showToast(msg, type) {
        var wrap = document.getElementById('fmToastWrap');
        var el   = document.createElement('div');
        el.className   = 'fm-toast ' + type;
        el.textContent = msg;
        wrap.appendChild(el);
        setTimeout(function () {
            el.style.transition = 'opacity .3s';
            el.style.opacity    = '0';
            setTimeout(function () { el.remove(); }, 350);
        }, 3200);
    }

    /* ── Add Fee Title form submit ────────────────────────────────────── */
    document.getElementById('add_fees_title').addEventListener('submit', function (e) {
        e.preventDefault();

        var feeTitle = document.getElementById('fee_title').value.trim();
        var feeType  = document.getElementById('fee_type').value;

        if (!feeTitle || !feeType) {
            showToast('Please fill in all required fields.', 'error');
            return;
        }

        /* Build FormData from the form, then inject CSRF token */
        var fd = new FormData(this);
        fd.append(CSRF_NAME, CSRF_HASH); /* ← CSRF body field for CI filter  */

        fetch('<?= base_url('fees/fees_structure') ?>', {
            method:  'POST',
            body:    fd,
            headers: {
                'X-CSRF-Token':   CSRF_HASH,          /* ← header for MY_Controller  */
                'X-Requested-With': 'XMLHttpRequest'  /* marks request as AJAX       */
            }
        })
        .then(function (res) { return res.text(); })
        .then(function (res) {
            if (res.trim() === '1') {
                showToast('Fee title saved successfully!', 'success');
                setTimeout(function () {
                    window.location.href = '<?= base_url('fees/fees_structure') ?>';
                }, 900);
            } else {
                showToast('Failed to save fee title. Please try again.', 'error');
            }
        })
        .catch(function () {
            showToast('Server error occurred. Please try again.', 'error');
        });
    });

})();
</script>


<style>
    @import url('https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Fraunces:ital,wght@0,600;0,700;1,600&display=swap');

    :root {
        --fm-navy: #0f1f3d;
        --fm-teal: #0d7377;
        --fm-teal2: #14a085;
        --fm-sky: #e6f4f1;
        --fm-gold: #d97706;
        --fm-red: #e53e3e;
        --fm-green: #27ae60;
        --fm-text: #1a2940;
        --fm-muted: #64748b;
        --fm-border: #d1e8e4;
        --fm-bg: #f0f6f5;
        --fm-card: #ffffff;
        --fm-shadow: 0 2px 16px rgba(13, 115, 119, .10);
        --fm-radius: 14px;
    }

    * {
        box-sizing: border-box;
    }

    .fm-wrap {
        font-family: 'Plus Jakarta Sans', sans-serif;
        background: var(--fm-bg);
        color: var(--fm-text);
        padding: 24px 20px 60px;
        min-height: 100vh;
    }

    /* Top bar */
    .fm-topbar {
        margin-bottom: 24px;
    }

    .fm-page-title {
        font-family: 'Fraunces', serif;
        font-size: 1.6rem;
        font-weight: 700;
        color: var(--fm-navy);
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 6px;
    }

    .fm-page-title i {
        color: var(--fm-teal);
    }

    .fm-breadcrumb {
        display: flex;
        align-items: center;
        gap: 6px;
        font-size: .78rem;
        color: var(--fm-muted);
        list-style: none;
        margin: 0;
        padding: 0;
    }

    .fm-breadcrumb a {
        color: var(--fm-teal);
        text-decoration: none;
        font-weight: 600;
    }

    .fm-breadcrumb li::before {
        content: '/';
        margin-right: 6px;
        color: var(--fm-border);
    }

    .fm-breadcrumb li:first-child::before {
        display: none;
    }

    /* Card */
    .fm-card {
        background: var(--fm-card);
        border-radius: var(--fm-radius);
        box-shadow: var(--fm-shadow);
        border: 1px solid var(--fm-border);
        margin-bottom: 22px;
        overflow: hidden;
    }

    .fm-card-head {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 14px 20px;
        border-bottom: 1px solid var(--fm-border);
        background: linear-gradient(135deg, var(--fm-sky) 0%, #fff 100%);
    }

    .fm-card-head h3 {
        font-family: 'Fraunces', serif;
        font-size: .92rem;
        font-weight: 700;
        color: var(--fm-navy);
        margin: 0;
    }

    .fm-card-head i {
        color: var(--fm-teal);
        font-size: .92rem;
    }

    .fm-req { color: var(--fm-red); }

    .fm-card-body {
        padding: 22px;
    }

    /* Form */
    .fm-grid-form {
        display: grid;
        grid-template-columns: 1fr 1fr auto;
        gap: 16px;
        align-items: end;
    }

    @media(max-width:700px) {
        .fm-grid-form {
            grid-template-columns: 1fr;
        }
    }

    .fm-label {
        display: block;
        font-size: .82rem;
        font-weight: 700;
        letter-spacing: .4px;
        text-transform: uppercase;
        color: var(--fm-muted);
        margin-bottom: 6px;
    }

    .fm-input,
    .fm-select {
        width: 100%;
        height: 40px;
        padding: 0 12px;
        border: 1.5px solid var(--fm-border);
        border-radius: 8px;
        font-family: 'Plus Jakarta Sans', sans-serif;
        font-size: .82rem;
        color: var(--fm-text);
        background: var(--fm-sky);
        outline: none;
        transition: border-color .13s, box-shadow .13s;
    }

    .fm-input:focus,
    .fm-select:focus {
        border-color: var(--fm-teal);
        box-shadow: 0 0 0 3px rgba(13, 115, 119, .12);
        background: var(--fm-card);
    }

    /* Buttons */
    .fm-btn {
        display: inline-flex;
        align-items: center;
        gap: 7px;
        padding: 9px 18px;
        border-radius: 8px;
        font-family: 'Plus Jakarta Sans', sans-serif;
        font-size: .82rem;
        font-weight: 600;
        cursor: pointer;
        border: none;
        transition: all .15s;
        text-decoration: none;
        white-space: nowrap;
    }

    .fm-btn-primary {
        background: var(--fm-teal);
        color: #fff;
        height: 40px;
    }

    .fm-btn-primary:hover {
        background: #0a6d6d;
        filter: brightness(.92);
    }

    .fm-btn-danger {
        background: var(--fm-red);
        color: #fff;
    }

    .fm-btn-danger:hover {
        filter: brightness(.88);
    }

    .fm-btn-sm {
        padding: 5px 11px;
        font-size: .75rem;
    }

    /* Stats */
    .fm-stats {
        display: flex;
        gap: 14px;
        flex-wrap: wrap;
        margin-bottom: 22px;
    }

    .fm-stat {
        background: var(--fm-card);
        border: 1px solid var(--fm-border);
        border-radius: var(--fm-radius);
        box-shadow: var(--fm-shadow);
        padding: 14px 20px;
        flex: 1;
        min-width: 140px;
    }

    .fm-stat-label {
        font-size: .8rem;
        font-weight: 700;
        letter-spacing: .6px;
        text-transform: uppercase;
        color: var(--fm-muted);
        margin-bottom: 4px;
    }

    .fm-stat-value {
        font-family: 'Fraunces', serif;
        font-size: 1.25rem;
        font-weight: 700;
        color: var(--fm-navy);
    }

    .fm-stat.teal .fm-stat-value {
        color: var(--fm-teal);
    }

    .fm-stat.gold .fm-stat-value {
        color: var(--fm-gold);
    }

    /* Table */
    .fm-table-wrap {
        overflow-x: auto;
    }

    .fm-table {
        width: 100%;
        border-collapse: collapse;
        font-size: .82rem;
    }

    .fm-table th {
        background: var(--fm-navy);
        color: #fff;
        padding: 10px 14px;
        text-align: left;
        font-size: .82rem;
        letter-spacing: .4px;
        text-transform: uppercase;
        font-weight: 700;
        white-space: nowrap;
    }

    .fm-table td {
        padding: 10px 14px;
        border-bottom: 1px solid var(--fm-border);
        vertical-align: middle;
    }

    .fm-table tbody tr:hover {
        background: var(--fm-sky);
    }

    .fm-table tbody tr:last-child td {
        border-bottom: none;
    }

    /* Badge */
    .fm-badge {
        display: inline-flex;
        align-items: center;
        padding: 3px 10px;
        border-radius: 20px;
        font-size: .7rem;
        font-weight: 700;
    }

    .fm-badge-teal {
        background: var(--fm-sky);
        color: var(--fm-teal);
    }

    .fm-badge-gold {
        background: rgba(217, 119, 6, .12);
        color: var(--fm-gold);
    }

    /* Empty state */
    .fm-empty {
        text-align: center;
        padding: 40px 20px;
        color: var(--fm-muted);
    }

    .fm-empty i {
        font-size: 2.25rem;
        margin-bottom: 10px;
        display: block;
        opacity: .4;
    }

    /* Toast */
    .fm-toast-wrap {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 99999;
        display: flex;
        flex-direction: column;
        gap: 10px;
        pointer-events: none;
    }

    .fm-toast {
        padding: 12px 18px;
        border-radius: 8px;
        color: #fff;
        font-size: .82rem;
        font-weight: 600;
        font-family: 'Plus Jakarta Sans', sans-serif;
        box-shadow: 0 4px 20px rgba(0, 0, 0, .2);
        animation: fm-toast-in .25s ease;
        pointer-events: auto;
        max-width: 320px;
    }

    .fm-toast.success {
        background: var(--fm-green);
    }

    .fm-toast.error {
        background: var(--fm-red);
    }

    @keyframes fm-toast-in {
        from {
            transform: translateX(40px);
            opacity: 0;
        }

        to {
            transform: translateX(0);
            opacity: 1;
        }
    }
</style>