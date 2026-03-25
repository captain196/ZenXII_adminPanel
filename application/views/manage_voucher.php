<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>


<div class="content-wrapper">
    <div class="mv-wrap">

        <!-- ══ TOP BAR ══ -->
        <div class="mv-topbar">
            <div>
                <h1 class="mv-page-title">
                    <i class="fa fa-file-text-o"></i> Make Vouchers
                </h1>
                <ul class="mv-breadcrumb">
                    <li><a href="<?= base_url('admin') ?>"><i class="fa fa-home"></i> Home</a></li>
                    <li>Accounts</li>
                    <li>Make Vouchers</li>
                </ul>
            </div>
            <div class="mv-session-badge">
                <span class="mv-badge-label">Session</span>
                <span class="mv-badge-val"><?= htmlspecialchars($session_year ?? date('Y') . '-' . (date('Y') + 1)) ?></span>
            </div>
        </div>

        <!-- ══ VOUCHER ENTRY CARD ══ -->
        <div class="mv-card">
            <div class="mv-card-head">
                <i class="fa fa-pencil-square-o"></i>
                <h3>Voucher Entry</h3>
                <span class="mv-card-head-hint">Select a voucher type to begin entering data</span>
            </div>
            <div class="mv-card-body">

                <!-- Voucher Type + Date -->
                <div class="mv-top-row">
                    <div class="mv-fc">
                        <label class="mv-label" for="voucherType">
                            Voucher Type <span class="mv-req">*</span>
                        </label>
                        <div class="mv-select-wrap">
                            <select id="voucherType" class="mv-select" onchange="autoFillDrCr()">
                                <option value="" disabled selected>Select Voucher Type</option>
                                <option value="payment">Payment</option>
                                <option value="receipt">Receipt</option>
                                <option value="Contra">Contra</option>
                                <option value="Journal">Journal</option>
                            </select>
                        </div>
                    </div>
                    <div class="mv-fc-sm">
                        <label class="mv-label" for="date">
                            Date <span class="mv-req">*</span>
                        </label>
                        <input type="date" id="date" class="mv-input" disabled>
                    </div>
                </div>

                <!-- Voucher Entry Table -->
                <div class="mv-tbl-outer">
                    <table class="mv-tbl">
                        <thead>
                            <tr>
                                <th>Dr / Cr</th>
                                <th>Ledger Name</th>
                                <th>Debit Amount</th>
                                <th>Credit Amount</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>
                                    <input type="text" id="drCr1" class="mv-input" placeholder="Dr/Cr" disabled readonly>
                                </td>
                                <td>
                                    <input type="text" id="ledger1" class="mv-input"
                                        onclick="openModal('ledger1','drCr1')"
                                        placeholder="Click to select account" disabled readonly>
                                </td>
                                <td>
                                    <input type="text" id="drAmount1" class="mv-input"
                                        oninput="autoFillCrAmount(1)" placeholder="0.00" disabled>
                                </td>
                                <td>
                                    <input type="text" id="crAmount1" class="mv-input"
                                        oninput="autoFillDrAmount(1)" placeholder="0.00" disabled>
                                </td>
                            </tr>
                            <tr>
                                <td>
                                    <input type="text" id="drCr2" class="mv-input" placeholder="Dr/Cr" disabled readonly>
                                </td>
                                <td>
                                    <input type="text" id="ledger2" class="mv-input"
                                        onclick="openSecondModal('ledger2','drCr2')"
                                        placeholder="Click to select mode / account" disabled readonly>
                                </td>
                                <td>
                                    <input type="text" id="drAmount2" class="mv-input" placeholder="0.00" disabled>
                                </td>
                                <td>
                                    <input type="text" id="crAmount2" class="mv-input" placeholder="0.00" disabled>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>

                <!-- Reference No -->
                <div class="mv-ref-row">
                    <label class="mv-label" for="refNo">Ref. No :</label>
                    <input type="text" id="refNo" class="mv-input" placeholder="Reference number (optional)" disabled>
                </div>

                <!-- Buttons -->
                <div class="mv-action-row">
                    <div>
                        <a href="<?= base_url('account/account_book') ?>" class="mv-btn mv-btn-ghost mv-btn-sm">
                            <i class="fa fa-plus-circle"></i> Create Ledger
                        </a>
                    </div>
                    <div class="mv-action-right">
                        <button id="saveBtn" class="mv-btn mv-btn-green" onclick="saveVoucher()" disabled>
                            <i class="fa fa-save"></i> Save Voucher
                        </button>
                        <a href="<?= base_url('account/view_voucher') ?>" class="mv-btn mv-btn-teal">
                            <i class="fa fa-list-alt"></i> View Vouchers
                        </a>
                        <button type="button" class="mv-btn mv-btn-ghost mv-btn-sm" onclick="refreshPage()">
                            <i class="fa fa-refresh"></i> Reset
                        </button>
                    </div>
                </div>

            </div><!-- /card-body -->
        </div><!-- /card -->

    </div><!-- /mv-wrap -->

    <!-- ══ MODAL 1 — Primary Ledger ══ -->
    <div id="ledgerModal" class="mv-overlay">
        <div class="mv-modal">
            <div class="mv-modal-head">
                <h4><i class="fa fa-list"></i> Select Account</h4>
                <button class="mv-modal-close" onclick="closeModal()" title="Close">×</button>
            </div>
            <div class="mv-modal-search">
                <input type="text" id="mvSearch1" class="mv-modal-search-box"
                    placeholder="Search accounts…" oninput="mvFilter('mvList1', this.value)">
            </div>
            <ul class="mv-modal-list" id="mvList1">
                <?php if (!empty($accounts)): ?>
                    <?php foreach ($accounts as $a): ?>
                        <li onclick="selectLedger('<?= htmlspecialchars($a, ENT_QUOTES) ?>')"><?= htmlspecialchars($a) ?></li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li class="mv-modal-empty">No accounts available</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

    <!-- ══ MODAL 2 — Mode / Second Ledger ══ -->
    <div id="secondLedgerModal" class="mv-overlay">
        <div class="mv-modal">
            <div class="mv-modal-head">
                <h4><i class="fa fa-list"></i> <span id="modal2Title">Select Mode / Account</span></h4>
                <button class="mv-modal-close" onclick="closeSecondModal()" title="Close">×</button>
            </div>
            <div class="mv-modal-search">
                <input type="text" id="mvSearch2" class="mv-modal-search-box"
                    placeholder="Search…" oninput="mvFilter('mvList2', this.value)">
            </div>
            <ul class="mv-modal-list" id="mvList2">
                <?php if (!empty($accounts_2)): ?>
                    <?php foreach ($accounts_2 as $a): ?>
                        <li onclick="selectLedger('<?= htmlspecialchars($a, ENT_QUOTES) ?>')"><?= htmlspecialchars($a) ?></li>
                    <?php endforeach; ?>
                <?php else: ?>
                    <li class="mv-modal-empty">No accounts available</li>
                <?php endif; ?>
            </ul>
        </div>
    </div>

</div>

<div class="mv-toast-wrap" id="mvToasts"></div>



<script>
    (function() {
        /* ──────────────────────────────────────────
           DATA — PHP arrays available to JS
        ────────────────────────────────────────── */
        var ACC1 = <?= json_encode(array_values($accounts   ?? [])) ?>;
        var ACC2 = <?= json_encode(array_values($accounts_2 ?? [])) ?>;

        /* ──────────────────────────────────────────
           TOAST
        ────────────────────────────────────────── */
        function toast(msg, type) {
            var el = document.createElement('div');
            var ico = type === 's' ? 'check-circle' :
                type === 'e' ? 'times-circle' : 'exclamation-triangle';
            el.className = 'mv-toast mv-toast-' + type;
            el.innerHTML = '<i class="fa fa-' + ico + '"></i> ' + msg;
            document.getElementById('mvToasts').appendChild(el);
            setTimeout(function() {
                el.classList.add('mv-hiding');
                setTimeout(function() {
                    el.remove();
                }, 320);
            }, 3200);
        }

        /* ──────────────────────────────────────────
           MODAL FILTER
        ────────────────────────────────────────── */
        window.mvFilter = function(listId, q) {
            q = (q || '').toLowerCase();
            document.querySelectorAll('#' + listId + ' li').forEach(function(li) {
                li.style.display = li.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none';
            });
        };

        /* ──────────────────────────────────────────
           REBUILD MODAL LIST (called by autoFillDrCr)
        ────────────────────────────────────────── */
        function buildList(listId, arr) {
            var ul = document.getElementById(listId);
            ul.innerHTML = '';
            if (!arr || !arr.length) {
                ul.innerHTML = '<li class="mv-modal-empty">No accounts available</li>';
                return;
            }
            arr.forEach(function(name) {
                var li = document.createElement('li');
                li.textContent = name;
                li.onclick = function() {
                    selectLedger(name);
                };
                ul.appendChild(li);
            });
        }

        /* ──────────────────────────────────────────
           MODAL OPEN / CLOSE
        ────────────────────────────────────────── */
        var activeLedgerField = null;
        var activeDrCrField = null;

        window.openModal = function(lf, df) {
            if (!document.getElementById('voucherType').value) {
                toast('Please select a voucher type first.', 'w');
                return;
            }
            activeLedgerField = lf;
            activeDrCrField = df;
            document.getElementById('ledgerModal').classList.add('open');
            var s = document.getElementById('mvSearch1');
            s.value = '';
            mvFilter('mvList1', '');
            s.focus();
        };
        window.openSecondModal = function(lf, df) {
            if (!document.getElementById('voucherType').value) {
                toast('Please select a voucher type first.', 'w');
                return;
            }
            activeLedgerField = lf;
            activeDrCrField = df;
            document.getElementById('secondLedgerModal').classList.add('open');
            var s = document.getElementById('mvSearch2');
            s.value = '';
            mvFilter('mvList2', '');
            s.focus();
        };
        window.closeModal = function() {
            document.getElementById('ledgerModal').classList.remove('open');
        };
        window.closeSecondModal = function() {
            document.getElementById('secondLedgerModal').classList.remove('open');
        };

        /* Close on backdrop click */
        document.getElementById('ledgerModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        document.getElementById('secondLedgerModal').addEventListener('click', function(e) {
            if (e.target === this) closeSecondModal();
        });

        /* ESC key */
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal();
                closeSecondModal();
            }
        });

        /* ──────────────────────────────────────────
           SELECT LEDGER
        ────────────────────────────────────────── */
        window.selectLedger = function(name) {
            if (!activeLedgerField) return;
            document.getElementById(activeLedgerField).value = name;
            closeModal();
            closeSecondModal();

            var vt = document.getElementById('voucherType').value;
            var isReceipt = (vt === 'receipt');

            if (activeLedgerField === 'ledger1') {
                if (isReceipt) {
                    document.getElementById('drAmount1').value = '00';
                    document.getElementById('crAmount1').value = '';
                } else {
                    document.getElementById('crAmount1').value = '00';
                    document.getElementById('drAmount1').value = '';
                }
            }

            if (activeLedgerField === 'ledger2') {
                if (isReceipt) {
                    document.getElementById('drAmount2').value = document.getElementById('crAmount1').value;
                    document.getElementById('crAmount2').value = '00';
                } else {
                    document.getElementById('drAmount2').value = '00';
                    document.getElementById('crAmount2').value = document.getElementById('drAmount1').value;
                }
            }

            checkFormValidity();
        };

        /* ──────────────────────────────────────────
           DR / CR COLOUR HELPER
        ────────────────────────────────────────── */
        function styleDrCr(id, val) {
            var el = document.getElementById(id);
            el.classList.remove('mv-drcr-dr', 'mv-drcr-cr');
            if (val === 'Dr') el.classList.add('mv-drcr-dr');
            if (val === 'Cr') el.classList.add('mv-drcr-cr');
        }

        /* ──────────────────────────────────────────
           AUTO FILL Dr/Cr — ALL FOUR VOUCHER TYPES
        ────────────────────────────────────────── */
        window.autoFillDrCr = function() {
            var vt = document.getElementById('voucherType').value;

            /* Enable date */
            document.getElementById('date').disabled = false;

            /* Reset all cell values */
            ['ledger1', 'ledger2', 'drCr1', 'drCr2', 'drAmount1', 'crAmount1', 'drAmount2', 'crAmount2']
            .forEach(function(id) {
                document.getElementById(id).value = '';
            });

            /* Defaults */
            var cfg = {
                dr1: '',
                cr1: '',
                dr2: '',
                cr2: '',
                dis_drAmount1: false,
                dis_crAmount1: false,
                dis_drAmount2: false,
                dis_crAmount2: false,
                list2: ACC2,
                modal2Title: 'Select Mode / Account',
                l2placeholder: 'Click to select mode of transaction'
            };

            if (vt === 'payment') {
                cfg.dr1 = 'Dr';
                cfg.cr1 = '';
                cfg.cr2str = '00';
                cfg.dr2str = '00';
                cfg.dis_crAmount1 = true;
                cfg.dis_drAmount2 = true;
                cfg.crAmount1_val = '00';
                cfg.drAmount2_val = '00';
            } else if (vt === 'receipt') {
                cfg.dr1 = 'Cr';
                cfg.cr1 = 'Dr';
                cfg.dis_drAmount1 = true;
                cfg.dis_crAmount2 = true;
                cfg.drAmount1_val = '00';
                cfg.crAmount2_val = '00';
            } else if (vt === 'Contra') {
                cfg.dr1 = 'Cr';
                cfg.cr1 = 'Dr';
                cfg.dis_drAmount1 = true;
                cfg.dis_crAmount2 = true;
                cfg.drAmount1_val = '00';
                cfg.crAmount2_val = '00';
                cfg.list2 = ACC1;
                cfg.modal2Title = 'Select Account';
                cfg.l2placeholder = 'Click to select account';
            } else if (vt === 'Journal') {
                cfg.dr1 = 'Dr';
                cfg.cr1 = 'Cr';
                cfg.dis_crAmount1 = true;
                cfg.dis_drAmount2 = true;
                cfg.crAmount1_val = '00';
                cfg.drAmount2_val = '00';
                cfg.list2 = ACC1;
                cfg.modal2Title = 'Select Account';
                cfg.l2placeholder = 'Click to select account';
            }

            /* Apply Dr/Cr labels */
            document.getElementById('drCr1').value = cfg.dr1 || '';
            document.getElementById('drCr2').value = cfg.cr1 || '';
            styleDrCr('drCr1', cfg.dr1);
            styleDrCr('drCr2', cfg.cr1);

            /* Enable ledger fields */
            ['ledger1', 'ledger2', 'refNo'].forEach(function(id) {
                document.getElementById(id).disabled = false;
            });

            /* Amount enable / pre-fill */
            document.getElementById('drAmount1').disabled = !!cfg.dis_drAmount1;
            document.getElementById('crAmount1').disabled = !!cfg.dis_crAmount1;
            document.getElementById('drAmount2').disabled = !!cfg.dis_drAmount2;
            document.getElementById('crAmount2').disabled = !!cfg.dis_crAmount2;
            if (cfg.crAmount1_val !== undefined) document.getElementById('crAmount1').value = cfg.crAmount1_val;
            if (cfg.drAmount1_val !== undefined) document.getElementById('drAmount1').value = cfg.drAmount1_val;
            if (cfg.drAmount2_val !== undefined) document.getElementById('drAmount2').value = cfg.drAmount2_val;
            if (cfg.crAmount2_val !== undefined) document.getElementById('crAmount2').value = cfg.crAmount2_val;

            /* Ledger 2 placeholder */
            document.getElementById('ledger2').placeholder = cfg.l2placeholder;

            /* Rebuild modal 2 list */
            document.getElementById('modal2Title').textContent = cfg.modal2Title;
            buildList('mvList2', cfg.list2);

            checkFormValidity();
        };

        /* ──────────────────────────────────────────
           AMOUNT CROSS-FILL
        ────────────────────────────────────────── */
        window.autoFillCrAmount = function(row) {
            var v = document.getElementById('drAmount' + row).value;
            var next = document.getElementById('crAmount' + (row + 1));
            if (next && !next.disabled) next.value = v;
            checkFormValidity();
        };
        window.autoFillDrAmount = function(row) {
            var v = document.getElementById('crAmount' + row).value;
            var next = document.getElementById('drAmount' + (row + 1));
            if (next && !next.disabled) next.value = v;
            checkFormValidity();
        };

        /* ──────────────────────────────────────────
           FORM VALIDITY → enable / disable Save btn
        ────────────────────────────────────────── */
        function isFieldValid(id) {
            var el = document.getElementById(id);
            return el && !el.disabled && el.value.trim() !== '';
        }

        function checkFormValidity() {
            var ok = document.getElementById('ledger1').value.trim() !== '' &&
                document.getElementById('ledger2').value.trim() !== '' &&
                (isFieldValid('drAmount1') || isFieldValid('crAmount1') ||
                    isFieldValid('drAmount2') || isFieldValid('crAmount2'));
            document.getElementById('saveBtn').disabled = !ok;
        }
        ['ledger1', 'ledger2', 'drAmount1', 'crAmount1', 'drAmount2', 'crAmount2'].forEach(function(id) {
            document.getElementById(id).addEventListener('input', checkFormValidity);
        });

        /* ──────────────────────────────────────────
           FETCH SERVER DATE
        ────────────────────────────────────────── */
        window.onload = function() {
            $.ajax({
                url: '<?= site_url("account/get_server_date") ?>',
                type: 'GET',
                dataType: 'json',
                success: function(r) {
                    if (r && r.date) {
                        /* response is DD-MM-YYYY → convert to YYYY-MM-DD for <input type="date"> */
                        var p = r.date.split('-');
                        if (p.length === 3) {
                            document.getElementById('date').value = p[2] + '-' + p[1] + '-' + p[0];
                        }
                    }
                },
                error: function() {
                    console.warn('Could not fetch server date.');
                }
            });
        };

        /* ──────────────────────────────────────────
           SAVE VOUCHER
        ────────────────────────────────────────── */
        window.saveVoucher = function() {
            var vt = document.getElementById('voucherType').value;
            var date = document.getElementById('date').value;
            var account = document.getElementById('ledger1').value;
            var mode = document.getElementById('ledger2').value;
            var refNo = document.getElementById('refNo').value;
            var cr1 = document.getElementById('crAmount1').value;
            var dr1 = document.getElementById('drAmount1').value;

            var fDate = typeof formatDateToDMY === 'function' ? formatDateToDMY(date) : date;

            var vData = {
                Acc: account,
                Mode: mode,
                Refer: refNo
            };

            if (vt === 'Contra' && !document.getElementById('crAmount1').disabled && parseFloat(cr1) > 0) vData.Contra = cr1;
            else if (vt === 'Journal' && !document.getElementById('drAmount1').disabled && parseFloat(dr1) > 0) vData.Journal = dr1;
            else if (vt === 'receipt' && !document.getElementById('crAmount1').disabled && parseFloat(cr1) > 0) vData.Receipt = cr1;
            else if (vt === 'payment' && !document.getElementById('drAmount1').disabled && parseFloat(dr1) > 0) vData.Payment = dr1;
            else {
                toast('Please enter a valid amount before saving.', 'w');
                return;
            }

            var $btn = document.getElementById('saveBtn');
            $btn.disabled = true;
            $btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

            $.ajax({
                url: '<?= site_url("account/save_voucher") ?>',
                method: 'POST',
                data: {
                    date: fDate,
                    voucher: vData
                },
                success: function() {
                    toast('Voucher saved successfully!', 's');
                    setTimeout(function() {
                        location.reload();
                    }, 1200);
                },
                error: function() {
                    toast('Failed to save voucher. Please try again.', 'e');
                    $btn.disabled = false;
                    $btn.innerHTML = '<i class="fa fa-save"></i> Save Voucher';
                }
            });
        };

        window.refreshPage = function() {
            window.location.reload();
        };

    })();
</script>


<style>
    @import url('https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700&display=swap');

    /* ══ TOKENS — identical to account_book / view_accounts ══ */
    :root {
        --ab-navy: #0b1f3a;
        --ab-teal: #0e7490;
        --ab-sky: #e0f2fe;
        --ab-green: #16a34a;
        --ab-red: #dc2626;
        --ab-amber: #0f766e;
        --ab-text: #1e293b;
        --ab-muted: #64748b;
        --ab-border: #e2e8f0;
        --ab-white: #ffffff;
        --ab-bg: #f1f5f9;
        --ab-shadow: 0 1px 14px rgba(11, 31, 58, .08);
        --ab-radius: 12px;
    }

    *,
    *::before,
    *::after {
        box-sizing: border-box;
    }

    /* ══ PAGE SHELL ══ */
    .mv-wrap {
        font-family: 'DM Sans', sans-serif;
        background: var(--ab-bg);
        color: var(--ab-text);
        padding: 24px 20px 60px;
        min-height: 100%;
    }

    /* ══ TOP BAR ══ */
    .mv-topbar {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 12px;
        margin-bottom: 22px;
    }

    .mv-page-title {
        font-family: 'Playfair Display', serif;
        font-size: 22px;
        font-weight: 700;
        color: var(--ab-navy);
        display: flex;
        align-items: center;
        gap: 10px;
        margin: 0 0 5px;
    }

    .mv-page-title i {
        color: var(--ab-teal);
    }

    .mv-breadcrumb {
        display: flex;
        align-items: center;
        list-style: none;
        margin: 0;
        padding: 0;
        font-size: 12.5px;
        color: var(--ab-muted);
    }

    .mv-breadcrumb a {
        color: var(--ab-teal);
        text-decoration: none !important;
        font-weight: 500;
    }

    .mv-breadcrumb li+li::before {
        content: '/';
        margin: 0 7px;
        color: #cbd5e1;
    }

    .mv-session-badge {
        display: flex;
        flex-direction: column;
        align-items: flex-end;
        gap: 2px;
    }

    .mv-badge-label {
        font-size: 10px;
        font-weight: 700;
        letter-spacing: .6px;
        text-transform: uppercase;
        color: var(--ab-muted);
    }

    .mv-badge-val {
        font-family: 'Playfair Display', serif;
        font-size: 17px;
        font-weight: 700;
        color: var(--ab-navy);
        line-height: 1;
    }

    /* ══ CARD ══ */
    .mv-card {
        background: var(--ab-white);
        border-radius: var(--ab-radius);
        box-shadow: var(--ab-shadow);
        border: 1px solid var(--ab-border);
        overflow: hidden;
        margin-bottom: 16px;
    }

    .mv-card-head {
        display: flex;
        align-items: center;
        gap: 9px;
        padding: 13px 18px;
        border-bottom: 1px solid var(--ab-border);
        background: linear-gradient(90deg, var(--ab-sky) 0%, var(--ab-white) 100%);
    }

    .mv-card-head i {
        color: var(--ab-teal);
        font-size: 14px;
    }

    .mv-card-head h3 {
        font-family: 'Playfair Display', serif;
        font-size: 14.5px;
        font-weight: 700;
        color: var(--ab-navy);
        margin: 0;
    }

    .mv-card-head-hint {
        margin-left: auto;
        font-size: 11px;
        color: var(--ab-muted);
        font-style: italic;
    }

    .mv-card-body {
        padding: 20px 18px;
    }

    /* ══ FORM LABELS & INPUTS ══ */
    .mv-label {
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
        color: var(--ab-muted);
        display: block;
        margin-bottom: 5px;
    }

    .mv-req {
        color: var(--ab-red);
    }

    .mv-input,
    .mv-select {
        height: 38px;
        padding: 0 10px;
        border: 1.5px solid var(--ab-border);
        border-radius: 8px;
        font-size: 13.5px;
        font-family: 'DM Sans', sans-serif;
        color: var(--ab-text);
        background: #fafcff;
        outline: none;
        width: 100%;
        transition: border-color .13s, box-shadow .13s;
    }

    .mv-select {
        padding-right: 32px;
        appearance: none;
        cursor: pointer;
    }

    .mv-select-wrap {
        position: relative;
    }

    .mv-select-wrap::after {
        content: '\f078';
        font-family: 'Font Awesome 5 Free';
        font-weight: 900;
        position: absolute;
        right: 10px;
        top: 50%;
        transform: translateY(-50%);
        color: var(--ab-muted);
        font-size: 10px;
        pointer-events: none;
    }

    .mv-input:focus,
    .mv-select:focus {
        border-color: var(--ab-teal);
        box-shadow: 0 0 0 3px rgba(14, 116, 144, .10);
    }

    .mv-input:disabled,
    .mv-select:disabled {
        background: #f1f5f9;
        color: #94a3b8;
        border-color: #e2e8f0;
        cursor: not-allowed;
    }

    .mv-input[readonly] {
        cursor: pointer;
    }

    /* ══ TOP FILTER ROW ══ */
    .mv-top-row {
        display: flex;
        flex-wrap: wrap;
        gap: 16px;
        align-items: flex-end;
        margin-bottom: 22px;
        padding-bottom: 20px;
        border-bottom: 1px solid var(--ab-border);
    }

    .mv-fc {
        flex: 1;
        min-width: 180px;
        display: flex;
        flex-direction: column;
    }

    .mv-fc-sm {
        flex: 0 0 200px;
        display: flex;
        flex-direction: column;
    }

    /* ══ VOUCHER TABLE ══ */
    .mv-tbl-outer {
        overflow-x: auto;
        border-radius: 8px;
        border: 1px solid var(--ab-border);
        margin-bottom: 20px;
    }

    table.mv-tbl {
        width: 100%;
        border-collapse: collapse;
        font-size: 13.5px;
        font-family: 'DM Sans', sans-serif;
    }

    table.mv-tbl thead th {
        background: var(--ab-navy);
        color: rgba(255, 255, 255, .88);
        font-size: 11px;
        font-weight: 700;
        letter-spacing: .5px;
        text-transform: uppercase;
        padding: 11px 14px;
        white-space: nowrap;
        text-align: center;
    }

    table.mv-tbl thead th:first-child {
        border-radius: 8px 0 0 0;
        text-align: left;
        width: 10%;
    }

    table.mv-tbl thead th:nth-child(2) {
        text-align: left;
        width: 44%;
    }

    table.mv-tbl thead th:nth-child(3),
    table.mv-tbl thead th:nth-child(4) {
        width: 23%;
    }

    table.mv-tbl thead th:last-child {
        border-radius: 0 8px 0 0;
    }

    table.mv-tbl tbody tr {
        border-bottom: 1px solid var(--ab-border);
        background: var(--ab-white);
    }

    table.mv-tbl tbody tr:last-child {
        border-bottom: none;
    }

    table.mv-tbl tbody td {
        padding: 10px 12px;
        vertical-align: middle;
    }

    /* Inputs inside table cells */
    table.mv-tbl td .mv-input {
        height: 36px;
        font-size: 13px;
        text-align: center;
    }

    table.mv-tbl td:nth-child(2) .mv-input {
        text-align: left;
    }

    table.mv-tbl td:nth-child(1) .mv-input {
        font-weight: 700;
        letter-spacing: .4px;
        text-align: center;
    }

    /* Dr / Cr color coding */
    input.mv-drcr-dr {
        color: var(--ab-red) !important;
        border-color: #fecaca !important;
        background: #fef2f2 !important;
    }

    input.mv-drcr-cr {
        color: var(--ab-green) !important;
        border-color: #bbf7d0 !important;
        background: #f0fdf4 !important;
    }

    /* ══ REFERENCE ROW ══ */
    .mv-ref-row {
        display: flex;
        align-items: center;
        gap: 12px;
        margin-bottom: 22px;
        padding-top: 4px;
    }

    .mv-ref-row .mv-label {
        white-space: nowrap;
        margin: 0;
        min-width: 70px;
    }

    .mv-ref-row .mv-input {
        flex: 1;
    }

    /* ══ ACTION ROW ══ */
    .mv-action-row {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px;
        padding-top: 18px;
        border-top: 1px solid var(--ab-border);
    }

    .mv-action-right {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
        align-items: center;
    }

    /* ══ BUTTONS ══ */
    .mv-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 7px;
        padding: 9px 18px;
        border-radius: 8px;
        border: none;
        font-family: 'DM Sans', sans-serif;
        font-size: 13px;
        font-weight: 600;
        cursor: pointer;
        transition: opacity .13s, transform .1s;
        white-space: nowrap;
        text-decoration: none !important;
    }

    .mv-btn:hover:not(:disabled) {
        opacity: .85;
        transform: translateY(-1px);
    }

    .mv-btn:disabled {
        opacity: .42;
        cursor: not-allowed;
        transform: none;
        pointer-events: none;
    }

    .mv-btn-teal {
        background: var(--ab-amber);
        color: #fff;
    }

    .mv-btn-navy {
        background: var(--ab-navy);
        color: #fff;
    }

    .mv-btn-green {
        background: var(--ab-green);
        color: #fff;
    }

    .mv-btn-red {
        background: var(--ab-red);
        color: #fff;
    }

    .mv-btn-amber {
        background: var(--ab-amber);
        color: #fff;
    }

    .mv-btn-ghost {
        background: var(--ab-white);
        color: var(--ab-text);
        border: 1.5px solid var(--ab-border);
    }

    .mv-btn-ghost:hover:not(:disabled) {
        border-color: var(--ab-teal);
        color: var(--ab-teal);
        background: var(--ab-sky);
    }

    .mv-btn-sm {
        padding: 7px 14px;
        font-size: 12px;
    }

    /* ══ MODALS ══ */
    .mv-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(11, 31, 58, .52);
        z-index: 9000;
        align-items: center;
        justify-content: center;
        padding: 16px;
    }

    .mv-overlay.open {
        display: flex;
    }

    .mv-modal {
        background: var(--ab-white);
        border-radius: var(--ab-radius);
        box-shadow: 0 20px 60px rgba(0, 0, 0, .22);
        width: 100%;
        max-width: 460px;
        overflow: hidden;
        animation: mv-drop .2s ease;
    }

    @keyframes mv-drop {
        from {
            transform: translateY(14px);
            opacity: 0
        }

        to {
            transform: translateY(0);
            opacity: 1
        }
    }

    .mv-modal-head {
        background: var(--ab-navy);
        color: #fff;
        padding: 13px 18px;
        display: flex;
        align-items: center;
        justify-content: space-between;
    }

    .mv-modal-head h4 {
        font-family: 'Playfair Display', serif;
        font-size: 15px;
        font-weight: 700;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .mv-modal-close {
        background: none;
        border: none;
        color: rgba(255, 255, 255, .6);
        font-size: 22px;
        cursor: pointer;
        line-height: 1;
        transition: color .13s;
        padding: 0;
    }

    .mv-modal-close:hover {
        color: #fff;
    }

    .mv-modal-search {
        padding: 12px 14px;
        border-bottom: 1px solid var(--ab-border);
        background: var(--ab-bg);
    }

    .mv-modal-search-box {
        width: 100%;
        height: 36px;
        padding: 0 10px 0 34px;
        border: 1.5px solid var(--ab-border);
        border-radius: 7px;
        font-size: 13px;
        font-family: 'DM Sans', sans-serif;
        background: var(--ab-white) url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2364748b' stroke-width='2'%3E%3Ccircle cx='11' cy='11' r='8'/%3E%3Cpath d='m21 21-4.35-4.35'/%3E%3C/svg%3E") no-repeat 10px center;
        color: var(--ab-text);
        outline: none;
        transition: border-color .13s;
    }

    .mv-modal-search-box:focus {
        border-color: var(--ab-teal);
        box-shadow: 0 0 0 3px rgba(14, 116, 144, .10);
    }

    .mv-modal-list {
        list-style: none;
        margin: 0;
        padding: 0;
        max-height: 320px;
        overflow-y: auto;
    }

    .mv-modal-list::-webkit-scrollbar {
        width: 4px;
    }

    .mv-modal-list::-webkit-scrollbar-thumb {
        background: #cbd5e1;
        border-radius: 4px;
    }

    .mv-modal-list li {
        padding: 11px 16px;
        border-bottom: 1px solid var(--ab-border);
        cursor: pointer;
        font-size: 13.5px;
        color: var(--ab-text);
        display: flex;
        align-items: center;
        gap: 9px;
        transition: background .1s;
    }

    .mv-modal-list li:last-child {
        border-bottom: none;
    }

    .mv-modal-list li::before {
        content: '';
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--ab-teal);
        flex-shrink: 0;
        opacity: .4;
        transition: opacity .1s;
    }

    .mv-modal-list li:hover {
        background: var(--ab-sky);
    }

    .mv-modal-list li:hover::before {
        opacity: 1;
    }

    .mv-modal-empty {
        padding: 32px 16px;
        text-align: center;
        color: var(--ab-muted);
        font-size: 13px;
        font-style: italic;
    }

    /* ══ TOAST ══ */
    .mv-toast-wrap {
        position: fixed;
        top: 20px;
        right: 20px;
        z-index: 99999;
        display: flex;
        flex-direction: column;
        gap: 8px;
        pointer-events: none;
    }

    .mv-toast {
        padding: 11px 16px;
        border-radius: 10px;
        color: #fff;
        font-family: 'DM Sans', sans-serif;
        font-size: 13px;
        font-weight: 600;
        box-shadow: 0 4px 18px rgba(0, 0, 0, .2);
        display: flex;
        align-items: center;
        gap: 8px;
        animation: mv-tin .22s ease;
        max-width: 320px;
        pointer-events: auto;
        transition: opacity .3s;
    }

    .mv-toast.mv-hiding {
        opacity: 0;
    }

    .mv-toast-s {
        background: var(--ab-green);
    }

    .mv-toast-e {
        background: var(--ab-red);
    }

    .mv-toast-w {
        background: var(--ab-amber);
    }

    @keyframes mv-tin {
        from {
            transform: translateX(20px);
            opacity: 0
        }

        to {
            transform: translateX(0);
            opacity: 1
        }
    }

    /* ══ RESPONSIVE ══ */
    @media (max-width: 640px) {
        .mv-top-row {
            flex-direction: column;
        }

        .mv-fc,
        .mv-fc-sm {
            min-width: 100%;
            flex: unset;
        }

        .mv-action-row {
            flex-direction: column;
            align-items: stretch;
        }

        .mv-action-right {
            flex-direction: column;
        }

        .mv-btn {
            width: 100%;
            justify-content: center;
        }

        table.mv-tbl thead th,
        table.mv-tbl tbody td {
            padding: 8px 8px;
        }
    }

    /* ══ PRINT ══ */
    @media print {

        .mv-topbar,
        .mv-action-row,
        .mv-toast-wrap,
        .mv-overlay,
        .mv-card-head-hint {
            display: none !important;
        }

        .mv-card {
            box-shadow: none;
            border: 1px solid #ccc;
        }
    }
</style>