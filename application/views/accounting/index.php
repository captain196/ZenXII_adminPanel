<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
/* ── Accounting Module — Production Styles ─────────────────────────── */
.ac-wrap {
    font-family: var(--font-b, 'Plus Jakarta Sans'), -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    max-width: 1440px; margin: 0 auto; padding: 24px 20px;
    color: var(--t1, #1a2e2a); line-height: 1.5; font-size: 14px;
    min-height: calc(100vh - 120px);
}
.ac-wrap *, .ac-wrap *::before, .ac-wrap *::after { box-sizing: border-box; }
.ac-mono { font-family: var(--font-m, 'JetBrains Mono'), 'Fira Code', 'Consolas', monospace; }

/* ── Theme vars with solid fallbacks ── */
.ac-wrap {
    --ac-primary: var(--gold, #0f766e);
    --ac-bg: var(--bg, #f0f7f5);
    --ac-bg2: var(--bg2, #ffffff);
    --ac-bg3: var(--bg3, #e6f4f1);
    --ac-border: var(--border, #d1ddd8);
    --ac-text: var(--t1, #1a2e2a);
    --ac-text2: var(--t2, #4a6a60);
    --ac-text3: var(--t3, #7a9a8e);
    --ac-card: var(--card, #ffffff);
    --ac-shadow: var(--sh, 0 2px 8px rgba(0,0,0,.06));
    --ac-r: 10px;
    --ac-green: #16a34a;
    --ac-red: #dc2626;
    --ac-blue: #2563eb;
    --ac-amber: #d97706;
}

/* ── Page Header ── */
.ac-header {
    display: flex; align-items: center; gap: 16px;
    margin-bottom: 24px; padding-bottom: 18px;
    border-bottom: 1px solid var(--ac-border);
}
.ac-header-icon {
    width: 48px; height: 48px; border-radius: 12px;
    background: linear-gradient(135deg, var(--ac-primary), #14b8a6);
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 22px; flex-shrink: 0;
}
.ac-header h2 {
    font-size: 24px; font-weight: 800; color: var(--ac-text);
    margin: 0; letter-spacing: -.3px; line-height: 1.2;
}
.ac-header p { font-size: 14px; color: var(--ac-text3); margin: 2px 0 0; }

/* ── Tab Navigation ── */
.ac-tabs {
    display: flex; gap: 2px; border-bottom: 2px solid var(--ac-border);
    margin-bottom: 24px; overflow-x: auto; padding: 0 2px;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}
.ac-tabs::-webkit-scrollbar { display: none; }
.ac-tab {
    padding: 12px 20px; font-size: 14px; font-weight: 600;
    color: var(--ac-text3); cursor: pointer;
    border-bottom: 3px solid transparent; margin-bottom: -2px;
    white-space: nowrap; transition: all .2s ease;
    display: flex; align-items: center; gap: 7px;
    border-radius: 6px 6px 0 0;
}
.ac-tab:hover { color: var(--ac-text); background: rgba(15,118,110,.04); }
.ac-tab.active {
    color: var(--ac-primary); border-bottom-color: var(--ac-primary);
    background: rgba(15,118,110,.06);
}
.ac-tab i { font-size: 14px; }
a.ac-tab { text-decoration: none; color: inherit; }
a.ac-tab:hover { text-decoration: none; color: var(--ac-text); }
a.ac-tab.active { color: var(--ac-primary); }

.ac-panel { display: none; }
.ac-panel.active { display: block; animation: acFadeIn .25s ease; }
@keyframes acFadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

/* ── Cards ── */
.ac-card {
    background: var(--ac-card); border: 1px solid var(--ac-border);
    border-radius: var(--ac-r); padding: 22px; margin-bottom: 18px;
    box-shadow: var(--ac-shadow);
}
.ac-card-title {
    font-size: 16px; font-weight: 700; color: var(--ac-text);
    margin-bottom: 16px; display: flex; align-items: center;
    gap: 10px; flex-wrap: wrap;
}
.ac-card-title i { color: var(--ac-primary); }

/* ── Buttons ── */
.ac-btn {
    display: inline-flex; align-items: center; gap: 7px;
    padding: 9px 18px; font-family: inherit; font-size: 13px; font-weight: 600;
    border-radius: 8px; border: none; cursor: pointer; transition: all .2s ease;
    text-decoration: none; line-height: 1.4;
}
.ac-btn-primary { background: var(--ac-primary, #0f766e); color: #fff; }
.ac-btn-primary:hover { background: #0d6b63; transform: translateY(-1px); box-shadow: 0 2px 8px rgba(15,118,110,.3); }
.ac-btn-danger { background: var(--ac-red, #dc2626); color: #fff; }
.ac-btn-danger:hover { background: #b91c1c; }
.ac-btn-ghost { background: transparent; color: var(--ac-primary, #0f766e); border: 1.5px solid var(--ac-primary, #0f766e); }
.ac-btn-ghost:hover { background: var(--ac-primary, #0f766e); color: #fff; }
.ac-btn-sm { padding: 6px 12px; font-size: 12px; border-radius: 6px; }
.ac-btn[disabled] { opacity: .45; cursor: not-allowed; pointer-events: none; }
.ac-btn i.fa, .ac-btn .fa { font-family: FontAwesome !important; }

/* ── Toolbar / Filters ── */
.ac-toolbar {
    display: flex; flex-wrap: wrap; gap: 12px; align-items: flex-end;
    padding: 16px 18px; background: var(--ac-bg3); border-radius: var(--ac-r);
    margin-bottom: 18px; border: 1px solid var(--ac-border);
}
.ac-fg { display: flex; flex-direction: column; gap: 4px; }
.ac-fg label {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .5px; color: var(--ac-text3);
}
.ac-fg input, .ac-fg select, .ac-fg textarea {
    font-family: inherit; font-size: 14px; padding: 8px 12px;
    border: 1.5px solid var(--ac-border); border-radius: 8px;
    background: var(--ac-bg2); color: var(--ac-text);
    transition: all .2s ease; min-height: 38px;
}
.ac-fg input:focus, .ac-fg select:focus, .ac-fg textarea:focus {
    outline: none; border-color: var(--ac-primary);
    box-shadow: 0 0 0 3px rgba(15,118,110,.1);
}

/* ── Tables ── */
.ac-table-wrap { overflow-x: auto; border-radius: var(--ac-r); }
.ac-table { width: 100%; border-collapse: collapse; font-size: 14px; }
.ac-table th {
    text-align: left; padding: 12px 14px; font-weight: 700; font-size: 12px;
    text-transform: uppercase; letter-spacing: .5px; color: var(--ac-text3);
    border-bottom: 2px solid var(--ac-border); background: var(--ac-bg3);
    white-space: nowrap;
}
.ac-table td {
    padding: 11px 14px; border-bottom: 1px solid var(--ac-border);
    color: var(--ac-text); vertical-align: middle;
}
.ac-table tbody tr:hover td { background: rgba(15,118,110,.04); }
.ac-table .ac-num { font-family: 'JetBrains Mono', monospace; text-align: right; font-size: 13px; }
.ac-table .ac-dr { color: var(--ac-green); font-weight: 600; }
.ac-table .ac-cr { color: var(--ac-red); font-weight: 600; }
.ac-table tfoot td { font-weight: 700; border-top: 2px solid var(--ac-border); background: var(--ac-bg3); }
.ac-table code { font-family: 'JetBrains Mono', monospace; font-size: 12px; color: var(--ac-text2); }

/* ── Badges ── */
.ac-badge {
    display: inline-block; padding: 3px 10px; border-radius: 12px;
    font-size: 11px; font-weight: 700; letter-spacing: .3px;
}
.ac-badge-asset { background: #dbeafe; color: #1e40af; }
.ac-badge-liability { background: #fce7f3; color: #9d174d; }
.ac-badge-equity { background: #ede9fe; color: #6d28d9; }
.ac-badge-income { background: #dcfce7; color: #166534; }
.ac-badge-expense { background: #fef3c7; color: #92400e; }
.ac-badge-finalized { background: #dcfce7; color: #166534; }
.ac-badge-draft { background: #fef3c7; color: #92400e; }
.ac-badge-matched { background: #dcfce7; color: #166534; }
.ac-badge-unmatched { background: #fee2e2; color: #991b1b; }

/* Voucher type display */
.ac-vtype-badge { display:inline-block; font-size:11px; padding:2px 8px; border-radius:4px; background:var(--ac-bg3); color:var(--ac-text2); font-weight:600; vertical-align:middle; }
.ac-vtype-sub { display:inline-block; font-size:10px; padding:1px 6px; border-radius:3px; margin-left:4px; background:rgba(15,118,110,.1); color:#0f766e; font-weight:500; vertical-align:middle; }

/* ── Modal ── */
.ac-modal-overlay {
    display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,.45); z-index: 10000;
    align-items: center; justify-content: center;
    /* Inherit theme vars — modals sit outside .ac-wrap in the DOM */
    --ac-primary: var(--gold, #0f766e);
    --ac-bg: var(--bg, #f0f7f5);
    --ac-bg2: var(--bg2, #ffffff);
    --ac-bg3: var(--bg3, #e6f4f1);
    --ac-border: var(--border, #d1ddd8);
    --ac-text: var(--t1, #1a2e2a);
    --ac-text2: var(--t2, #4a6a60);
    --ac-text3: var(--t3, #7a9a8e);
    --ac-card: var(--card, #ffffff);
    --ac-r: 10px;
    --ac-green: #16a34a;
    --ac-red: #dc2626;
    --ac-blue: #2563eb;
    --ac-amber: #d97706;
    font-family: var(--font-b, 'Plus Jakarta Sans'), -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
}
.ac-modal-overlay.show { display: flex !important; }
.ac-modal {
    background: #fff; border-radius: 16px; width: 94%; max-width: 720px;
    max-height: 88vh; overflow-y: auto; padding: 0;
    box-shadow: 0 20px 60px rgba(0,0,0,.35), 0 0 0 1px rgba(0,0,0,.05);
    position: relative;
}
[data-theme="dark"] .ac-modal,
.night .ac-modal { background: #1a2332; }
.ac-modal-header {
    display: flex; align-items: center; justify-content: space-between;
    padding: 20px 24px 16px; border-bottom: 1px solid var(--ac-border);
    position: sticky; top: 0; background: inherit; border-radius: 16px 16px 0 0; z-index: 1;
}
.ac-modal-title {
    font-size: 18px; font-weight: 800; margin: 0;
    color: var(--ac-text); letter-spacing: -.2px;
    display: flex; align-items: center; gap: 10px;
}
.ac-modal-title i { color: var(--ac-primary); font-size: 16px; }
.ac-modal-close {
    width: 34px; height: 34px; border-radius: 8px; border: none;
    background: var(--ac-bg3, #f1f5f9); color: var(--ac-text2, #64748b);
    font-size: 18px; cursor: pointer; display: flex; align-items: center;
    justify-content: center; transition: all .15s; flex-shrink: 0;
}
.ac-modal-close:hover { background: var(--ac-red, #ef4444); color: #fff; }
.ac-modal-body { padding: 20px 24px; }
.ac-modal-actions {
    display: flex; gap: 10px; justify-content: flex-end;
    padding: 16px 24px 20px; border-top: 1px solid var(--ac-border);
    position: sticky; bottom: 0; background: inherit; border-radius: 0 0 16px 16px;
}
/* ── Form in modal ── */
.ac-modal .ac-fg label {
    font-size: 12px; font-weight: 600; text-transform: none;
    letter-spacing: 0; color: var(--ac-text2, #475569);
}
.ac-modal .ac-fg input,
.ac-modal .ac-fg select,
.ac-modal .ac-fg textarea {
    background: var(--ac-bg, #f8fafc); border: 1.5px solid #b8cec6;
    font-size: 14px; padding: 10px 12px; border-radius: 8px;
    color: var(--ac-text); transition: border-color .15s, box-shadow .15s;
}
.ac-modal .ac-fg input:focus,
.ac-modal .ac-fg select:focus,
.ac-modal .ac-fg textarea:focus {
    border-color: var(--ac-primary);
    box-shadow: 0 0 0 3px rgba(15,118,110,.12);
    outline: none;
}
.ac-modal .ac-fg input::placeholder { color: var(--ac-text3, #94a3b8); }
[data-theme="dark"] .ac-modal .ac-fg input,
[data-theme="dark"] .ac-modal .ac-fg select,
[data-theme="dark"] .ac-modal .ac-fg textarea,
.night .ac-modal .ac-fg input,
.night .ac-modal .ac-fg select,
.night .ac-modal .ac-fg textarea {
    background: #1a2a3a; border-color: #2a3a4a; color: #e0e8f0;
}

/* ── Journal modal table inputs ── */
#journalModal .ac-table { border: 1px solid var(--ac-border); border-radius: 8px; overflow: hidden; }
#journalModal .ac-table th { background: var(--ac-bg3, #e6f4f1); }
#journalModal .ac-table td { padding: 8px 10px; background: var(--ac-bg, #f8fafc); }
#journalModal .ac-table select,
#journalModal .ac-table input[type="number"] {
    width: 100%; padding: 8px 10px; font-size: 13px;
    border: 1.5px solid #b8cec6; border-radius: 6px;
    background: var(--ac-bg2, #fff); color: var(--ac-text);
    font-family: inherit; transition: border-color .15s, box-shadow .15s;
}
#journalModal .ac-table input[type="number"] {
    text-align: right; font-family: 'JetBrains Mono', var(--font-m), monospace;
}
#journalModal .ac-table select:focus,
#journalModal .ac-table input[type="number"]:focus {
    outline: none; border-color: var(--ac-primary);
    box-shadow: 0 0 0 3px rgba(15,118,110,.12);
}
#journalModal .ac-table tfoot td { background: var(--ac-bg3, #e6f4f1); }
#journalModal .ac-table tfoot .ac-num { font-size: 14px; }

/* ── Add Line button ── */
.ac-btn-ghost.ac-btn-sm {
    font-size: 12px; padding: 7px 14px; border-radius: 6px;
    border: 1.5px dashed var(--ac-primary); color: var(--ac-primary);
    background: transparent; cursor: pointer; transition: all .15s;
    font-weight: 600; margin-top: 8px;
}
.ac-btn-ghost.ac-btn-sm:hover {
    background: var(--ac-primary); color: #fff; border-style: solid;
}

/* ── Journal modal dark mode ── */
[data-theme="dark"] #journalModal .ac-table td,
.night #journalModal .ac-table td { background: #0f1a2a; }
[data-theme="dark"] #journalModal .ac-table select,
[data-theme="dark"] #journalModal .ac-table input[type="number"],
.night #journalModal .ac-table select,
.night #journalModal .ac-table input[type="number"] {
    background: #1a2a3a; border-color: #2a3a4a; color: #e0e8f0;
}

/* ── Checkbox styling ── */
.ac-check-row {
    display: flex; align-items: center; gap: 8px;
    padding: 10px 14px; border-radius: 8px; border: 1.5px solid var(--ac-border);
    background: var(--ac-bg, #f8fafc); cursor: pointer; transition: all .15s;
    font-size: 13px; font-weight: 600; color: var(--ac-text2);
}
.ac-check-row:hover { border-color: var(--ac-primary); background: rgba(15,118,110,.04); }
.ac-check-row input[type="checkbox"] {
    width: 18px; height: 18px; accent-color: var(--ac-primary);
    cursor: pointer; margin: 0; flex-shrink: 0;
}
/* ── Form grid ── */
.ac-form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; }
.ac-form-grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 14px; }
.ac-form-full { grid-column: 1 / -1; }
.ac-form-section {
    font-size: 11px; font-weight: 700; text-transform: uppercase;
    letter-spacing: .6px; color: var(--ac-primary); margin: 16px 0 8px;
    padding-bottom: 6px; border-bottom: 1px dashed var(--ac-border);
    grid-column: 1 / -1;
}

/* ── Stats Row ── */
.ac-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 20px; }
.ac-stat {
    background: var(--ac-card); border: 1px solid var(--ac-border);
    border-radius: var(--ac-r); padding: 18px; text-align: center;
}
.ac-stat-label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .5px; color: var(--ac-text3); }
.ac-stat-value { font-size: 24px; font-weight: 800; font-family: 'JetBrains Mono', monospace; color: var(--ac-text); margin-top: 6px; }

/* ── Toast ── */
.ac-toast {
    position: fixed; bottom: 28px; right: 28px; padding: 14px 22px;
    border-radius: 10px; font-size: 14px; font-weight: 600; color: #fff;
    z-index: 9999; opacity: 0; transform: translateY(10px);
    transition: opacity .3s, transform .3s;
    box-shadow: 0 4px 16px rgba(0,0,0,.2);
}
.ac-toast.show { opacity: 1; transform: translateY(0); }
.ac-toast.success { background: var(--ac-green); }
.ac-toast.error { background: var(--ac-red); }

/* ── Responsive ── */
@media (max-width: 768px) {
    .ac-wrap { padding: 16px 12px; }
    .ac-header h2 { font-size: 20px; }
    .ac-tab { padding: 10px 14px; font-size: 13px; }
    .ac-toolbar { flex-direction: column; }
    .ac-stats { grid-template-columns: 1fr 1fr; }
    .ac-modal { padding: 20px; }
}

/* ── CoA hierarchy indents ── */
.ac-indent-1 { padding-left: 32px !important; }
.ac-indent-2 { padding-left: 52px !important; }
.ac-group-row td { font-weight: 700; background: var(--ac-bg3) !important; }

/* ── Empty state ── */
.ac-empty { text-align: center; color: var(--ac-text3); padding: 40px 20px; font-size: 14px; }
</style>

<div class="content-wrapper">
<section class="content">
<div class="ac-wrap">
    <div class="ac-header">
        <div class="ac-header-icon"><i class="fa fa-calculator"></i></div>
        <div>
            <h2>Accounting System</h2>
            <p>Double-entry accounting, reports & financial management</p>
        </div>
    </div>

    <?php
        $at = isset($active_tab) ? $active_tab : 'chart';
        $tab_map = [
            'chart'          => ['panel'=>'panelCoa',      'icon'=>'fa-sitemap',   'label'=>'Chart of Accounts'],
            'ledger'         => ['panel'=>'panelLedger',   'icon'=>'fa-book',      'label'=>'Journal Entries'],
            'income-expense' => ['panel'=>'panelIe',       'icon'=>'fa-exchange',  'label'=>'Income & Expense'],
            'cash-book'      => ['panel'=>'panelCashbook', 'icon'=>'fa-money',     'label'=>'Cash Book'],
            'bank-recon'     => ['panel'=>'panelBankrecon', 'icon'=>'fa-bank',     'label'=>'Bank Recon'],
            'reports'        => ['panel'=>'panelReports',  'icon'=>'fa-bar-chart', 'label'=>'Reports'],
            'settings'       => ['panel'=>'panelSettings', 'icon'=>'fa-cog',       'label'=>'Settings'],
        ];
    ?>
    <!-- Tabs -->
    <div class="ac-tabs" id="acTabs">
        <?php foreach ($tab_map as $slug => $t): ?>
        <a class="ac-tab<?= $slug === $at ? ' active' : '' ?>" data-tab="<?= $slug ?>" href="<?= base_url('accounting/' . $slug) ?>">
            <i class="fa <?= $t['icon'] ?>"></i> <?= $t['label'] ?>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- ═══════════ TAB 1: CHART OF ACCOUNTS ═══════════ -->
    <div class="ac-panel<?= $at === 'chart' ? ' active' : '' ?>" id="panelCoa">
        <div class="ac-card">
            <div class="ac-card-title">
                <span>Chart of Accounts</span>
                <span style="flex:1"></span>
                <button class="ac-btn ac-btn-primary ac-btn-sm" onclick="AC.showAccountModal()"><i class="fa fa-plus"></i> Add Account</button>
                <button class="ac-btn ac-btn-ghost ac-btn-sm ac-role-admin" onclick="AC.seedChart()" style="display:none" id="btnSeedChart"><i class="fa fa-magic"></i> Seed Defaults</button>
            </div>
            <div class="ac-table-wrap">
                <table class="ac-table" id="coaTable">
                    <thead><tr>
                        <th>Code</th><th>Account Name</th><th>Category</th><th>Type</th><th>Opening Bal</th><th>Current Bal</th><th>Actions</th>
                    </tr></thead>
                    <tbody id="coaBody"><tr><td colspan="7" class="ac-empty"><i class="fa fa-spinner fa-spin" style="font-size:18px;opacity:.4;"></i><br><small>Loading chart...</small></td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB 2: JOURNAL ENTRIES ═══════════ -->
    <div class="ac-panel<?= $at === 'ledger' ? ' active' : '' ?>" id="panelLedger">
        <div class="ac-toolbar">
            <div class="ac-fg"><label>From</label><input type="date" id="ledgerFrom"></div>
            <div class="ac-fg"><label>To</label><input type="date" id="ledgerTo"></div>
            <div class="ac-fg"><label>Account</label><select id="ledgerAcct"><option value="">All</option></select></div>
            <div class="ac-fg"><label>Type</label>
                <select id="ledgerVType">
                    <option value="">All</option>
                    <option>Journal</option><option>Receipt</option><option>Payment</option><option>Contra</option><option>Fee</option>
                </select>
            </div>
            <button class="ac-btn ac-btn-primary" onclick="AC.loadLedger()"><i class="fa fa-search"></i> Load</button>
            <button class="ac-btn ac-btn-ghost" onclick="AC.showJournalModal()"><i class="fa fa-plus"></i> New Entry</button>
        </div>
        <div class="ac-card">
            <div class="ac-table-wrap">
                <table class="ac-table" id="ledgerTable">
                    <thead><tr>
                        <th>Date</th><th>Voucher #</th><th>Type</th><th>Narration</th><th class="ac-num">Debit</th><th class="ac-num">Credit</th><th>Status</th><th>Actions</th>
                    </tr></thead>
                    <tbody id="ledgerBody"><tr><td colspan="8" class="ac-empty"><i class="fa fa-spinner fa-spin" style="font-size:18px;opacity:.4;"></i><br><small>Loading entries...</small></td></tr></tbody>
                </table>
            </div>
            <div id="ledgerPagination" style="text-align:center;margin-top:12px;display:none;">
                <span id="ledgerCount" style="font-size:13px;color:var(--ac-text2);margin-right:12px;"></span>
                <button class="ac-btn ac-btn-ghost ac-btn-sm" id="ledgerLoadMore" onclick="AC.loadMoreLedger()"><i class="fa fa-arrow-down"></i> Load More</button>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB 3: INCOME & EXPENSE ═══════════ -->
    <div class="ac-panel<?= $at === 'income-expense' ? ' active' : '' ?>" id="panelIe">
        <div class="ac-stats" id="ieSummary"></div>
        <div class="ac-toolbar">
            <div class="ac-fg"><label>Type</label>
                <select id="ieType"><option value="">All</option><option value="income">Income</option><option value="expense">Expense</option></select>
            </div>
            <div class="ac-fg"><label>From</label><input type="date" id="ieFrom"></div>
            <div class="ac-fg"><label>To</label><input type="date" id="ieTo"></div>
            <button class="ac-btn ac-btn-primary" onclick="AC.loadIE()"><i class="fa fa-search"></i> Load</button>
            <button class="ac-btn ac-btn-ghost" onclick="AC.showIEModal('income')"><i class="fa fa-plus"></i> Income</button>
            <button class="ac-btn ac-btn-ghost" onclick="AC.showIEModal('expense')"><i class="fa fa-plus"></i> Expense</button>
        </div>
        <div class="ac-card">
            <div class="ac-table-wrap">
                <table class="ac-table">
                    <thead><tr><th>Date</th><th>Type</th><th>Account</th><th>Description</th><th>Mode</th><th class="ac-num">Amount</th><th>Actions</th></tr></thead>
                    <tbody id="ieBody"><tr><td colspan="7" class="ac-empty"><i class="fa fa-spinner fa-spin" style="font-size:18px;opacity:.4;"></i><br><small>Loading records...</small></td></tr></tbody>
                </table>
            </div>
            <div id="iePagination" style="text-align:center;margin-top:12px;display:none;">
                <span id="ieCount" style="font-size:13px;color:var(--ac-text2);margin-right:12px;"></span>
                <button class="ac-btn ac-btn-ghost ac-btn-sm" id="ieLoadMore" onclick="AC.loadMoreIE()"><i class="fa fa-arrow-down"></i> Load More</button>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB 4: CASH BOOK ═══════════ -->
    <div class="ac-panel<?= $at === 'cash-book' ? ' active' : '' ?>" id="panelCashbook">
        <div class="ac-toolbar">
            <div class="ac-fg"><label>Account</label><select id="cbAccount"></select></div>
            <div class="ac-fg"><label>From</label><input type="date" id="cbFrom"></div>
            <div class="ac-fg"><label>To</label><input type="date" id="cbTo"></div>
            <button class="ac-btn ac-btn-primary" onclick="AC.loadCashBook()"><i class="fa fa-search"></i> Load</button>
        </div>
        <div class="ac-stats" id="cbStats"></div>
        <div id="cbAccountHeader" style="display:none;padding:12px 18px;background:var(--ac-bg3);border:1px solid var(--ac-border);border-radius:var(--ac-r);margin-bottom:14px;display:flex;align-items:center;gap:12px;">
            <div style="width:38px;height:38px;border-radius:8px;background:var(--ac-primary,#0f766e);display:flex;align-items:center;justify-content:center;color:#fff;font-size:15px;flex-shrink:0"><i class="fa fa-book"></i></div>
            <div>
                <div id="cbAcctName" style="font:700 15px/1.3 var(--font-b);color:var(--ac-text)"></div>
                <div id="cbAcctCode" style="font:400 12px/1.3 var(--font-m);color:var(--ac-text3)"></div>
            </div>
        </div>
        <div class="ac-card">
            <div class="ac-table-wrap">
                <table class="ac-table" id="cbTable">
                    <thead><tr><th>Date</th><th>Voucher</th><th>Type</th><th>Contra Account</th><th>Narration</th><th class="ac-num">Received (Dr)</th><th class="ac-num">Paid (Cr)</th><th class="ac-num">Balance</th></tr></thead>
                    <tbody id="cbBody"><tr><td colspan="8" class="ac-empty">Select an account and click Load.</td></tr></tbody>
                    <tfoot id="cbFoot" style="display:none"><tr>
                        <td colspan="5" style="text-align:right;font-weight:700">Totals:</td>
                        <td class="ac-num ac-dr" id="cbTotalDr" style="font-weight:700"></td>
                        <td class="ac-num ac-cr" id="cbTotalCr" style="font-weight:700"></td>
                        <td class="ac-num" id="cbClosingBal" style="font-weight:700"></td>
                    </tr></tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB 5: BANK RECONCILIATION ═══════════ -->
    <div class="ac-panel<?= $at === 'bank-recon' ? ' active' : '' ?>" id="panelBankrecon">
        <div class="ac-toolbar">
            <div class="ac-fg"><label>Bank Account</label><select id="brAccount"></select></div>
            <div class="ac-fg"><label>From</label><input type="date" id="brFrom"></div>
            <div class="ac-fg"><label>To</label><input type="date" id="brTo"></div>
            <button class="ac-btn ac-btn-primary" onclick="AC.loadBankRecon()"><i class="fa fa-search"></i> Load</button>
            <button class="ac-btn ac-btn-ghost" onclick="AC.showImportCSV()"><i class="fa fa-upload"></i> Import CSV</button>
        </div>
        <div class="ac-stats" id="brStats"></div>
        <div class="ac-card">
            <div class="ac-card-title">Bank Statement Entries</div>
            <div class="ac-table-wrap">
                <table class="ac-table">
                    <thead><tr><th>Date</th><th>Description</th><th>Reference</th><th class="ac-num">Debit</th><th class="ac-num">Credit</th><th>Status</th><th>Actions</th></tr></thead>
                    <tbody id="brBody"><tr><td colspan="7" class="ac-empty">Select a bank account and click Load.</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════ TAB 6: REPORTS ═══════════ -->
    <div class="ac-panel<?= $at === 'reports' ? ' active' : '' ?>" id="panelReports">
        <div class="ac-toolbar">
            <div class="ac-fg"><label>Report</label>
                <select id="rptType">
                    <option value="day_book">Day Book</option>
                    <option value="trial_balance">Trial Balance</option>
                    <option value="profit_loss">Profit & Loss</option>
                    <option value="balance_sheet">Balance Sheet</option>
                    <option value="cash_flow">Cash Flow</option>
                </select>
            </div>
            <div class="ac-fg"><label>As of / From</label><input type="date" id="rptFrom"></div>
            <div class="ac-fg"><label>To</label><input type="date" id="rptTo"></div>
            <button class="ac-btn ac-btn-primary" onclick="AC.generateReport()"><i class="fa fa-file-text-o"></i> Generate</button>
            <button class="ac-btn ac-btn-ghost" onclick="AC.exportReport('excel')" id="btnExportXl" style="display:none"><i class="fa fa-file-excel-o"></i> Excel</button>
            <button class="ac-btn ac-btn-ghost" onclick="AC.exportReport('pdf')" id="btnExportPdf" style="display:none"><i class="fa fa-file-pdf-o"></i> PDF</button>
        </div>
        <div class="ac-card" id="rptOutput"></div>
    </div>

    <!-- ═══════════ TAB 7: SETTINGS ═══════════ -->
    <div class="ac-panel<?= $at === 'settings' ? ' active' : '' ?>" id="panelSettings">
        <div class="ac-card">
            <div class="ac-card-title"><i class="fa fa-lock"></i> Period Lock</div>
            <p style="font-size:13px;color:var(--ac-text2);margin-bottom:12px;">
                Locking a period finalizes all journal entries on or before the selected date. This cannot be undone.
            </p>
            <div class="ac-toolbar" style="margin-bottom:0">
                <div class="ac-fg"><label>Current Lock</label><input type="text" id="settLockCurrent" readonly style="width:160px"></div>
                <div class="ac-fg"><label>Lock Until</label><input type="date" id="settLockDate"></div>
                <button class="ac-btn ac-btn-danger ac-role-admin" onclick="AC.lockPeriod()" style="display:none"><i class="fa fa-lock"></i> Lock Period</button>
            </div>
        </div>
        <div class="ac-card">
            <div class="ac-card-title"><i class="fa fa-database"></i> Migration</div>
            <p style="font-size:13px;color:var(--ac-text2);margin-bottom:12px;">
                Import existing Account Book entries into the Chart of Accounts.
            </p>
            <div id="migrationStatus" style="margin-bottom:12px;font-size:13px;"></div>
            <button class="ac-btn ac-btn-ghost ac-role-admin" onclick="AC.migrateAccounts()" style="display:none"><i class="fa fa-download"></i> Migrate Existing Accounts</button>
            <button class="ac-btn ac-btn-ghost ac-role-admin" onclick="AC.recomputeBalances()" style="margin-left:8px;display:none"><i class="fa fa-refresh"></i> Recompute Balances</button>
            <button class="ac-btn ac-btn-ghost ac-role-admin" onclick="AC.carryForward()" style="margin-left:8px;display:none"><i class="fa fa-forward"></i> Carry Forward Balances</button>
        </div>
        <div class="ac-card">
            <div class="ac-card-title"><i class="fa fa-hashtag"></i> Voucher Counters</div>
            <div id="settCounters" style="font-size:13px;color:var(--ac-text2);"></div>
        </div>
        <div class="ac-card">
            <div class="ac-card-title"><i class="fa fa-history"></i> Audit Trail</div>
            <p style="font-size:13px;color:var(--ac-text2);margin-bottom:12px;">
                Recent changes to accounting data.
            </p>
            <button class="ac-btn ac-btn-ghost ac-btn-sm" onclick="AC.loadAuditLog()" style="margin-bottom:12px"><i class="fa fa-refresh"></i> Refresh</button>
            <div class="ac-table-wrap">
                <table class="ac-table">
                    <thead><tr><th>Time</th><th>User</th><th>Action</th><th>Entity</th><th>Details</th></tr></thead>
                    <tbody id="auditBody"><tr><td colspan="5" class="ac-empty">Click Refresh to load audit trail.</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>
</div>
</section>
</div>

<!-- ══════ MODALS ══════ -->

<!-- Account Modal -->
<div class="ac-modal-overlay" id="accountModal">
    <div class="ac-modal">
        <div class="ac-modal-header">
            <div class="ac-modal-title"><i class="fa fa-sitemap"></i> <span id="accountModalTitle">Add Account</span></div>
            <button class="ac-modal-close" onclick="AC.closeModal('accountModal')">&times;</button>
        </div>
        <div class="ac-modal-body">
            <input type="hidden" id="amIsEdit" value="false">
            <div class="ac-form-grid">
                <div class="ac-fg"><label>Code *</label><input type="text" id="amCode" placeholder="e.g. 1021"></div>
                <div class="ac-fg"><label>Name *</label><input type="text" id="amName" placeholder="Account name"></div>
                <div class="ac-fg"><label>Category *</label>
                    <select id="amCategory">
                        <option value="">Select</option>
                        <option>Asset</option><option>Liability</option><option>Equity</option>
                        <option>Income</option><option>Expense</option>
                    </select>
                </div>
                <div class="ac-fg"><label>Sub-Category</label><input type="text" id="amSubCat" placeholder="e.g. Current Assets"></div>
                <div class="ac-fg"><label>Parent Code</label><input type="text" id="amParent" placeholder="e.g. 1000"></div>
                <div class="ac-fg"><label>Opening Balance</label><input type="number" id="amOpenBal" step="0.01" value="0"></div>
                <div class="ac-fg ac-form-full"><label>Description</label><input type="text" id="amDesc" placeholder="Optional description"></div>
                <div>
                    <label class="ac-check-row"><input type="checkbox" id="amIsGroup"> Group Account (not postable)</label>
                </div>
                <div>
                    <label class="ac-check-row"><input type="checkbox" id="amIsBank"> Bank Account</label>
                </div>
            </div>
            <div id="amBankFields" style="display:none;">
                <div class="ac-form-section"><i class="fa fa-bank"></i> Bank Details</div>
                <div class="ac-form-grid">
                    <div class="ac-fg"><label>Bank Name</label><input type="text" id="amBankName"></div>
                    <div class="ac-fg"><label>Branch</label><input type="text" id="amBranch"></div>
                    <div class="ac-fg"><label>Account No</label><input type="text" id="amAccNo"></div>
                    <div class="ac-fg"><label>IFSC</label><input type="text" id="amIfsc"></div>
                </div>
            </div>
        </div>
        <div class="ac-modal-actions">
            <button class="ac-btn ac-btn-ghost" onclick="AC.closeModal('accountModal')">Cancel</button>
            <button class="ac-btn ac-btn-primary" id="btnSaveAccount" onclick="AC.saveAccount()"><i class="fa fa-check"></i> Save</button>
        </div>
    </div>
</div>

<!-- Journal Entry Modal -->
<div class="ac-modal-overlay" id="journalModal">
    <div class="ac-modal" style="max-width:860px;">
        <div class="ac-modal-header">
            <div class="ac-modal-title"><i class="fa fa-book"></i> New Journal Entry</div>
            <button class="ac-modal-close" onclick="AC.closeModal('journalModal')">&times;</button>
        </div>
        <div class="ac-modal-body">
            <div class="ac-form-grid-3" style="margin-bottom:16px;">
                <div class="ac-fg"><label>Date *</label><input type="date" id="jeDate"></div>
                <div class="ac-fg"><label>Voucher Type</label>
                    <select id="jeVType"><option>Journal</option><option>Receipt</option><option>Payment</option><option>Contra</option></select>
                </div>
                <div class="ac-fg"><label>Voucher #</label><input type="text" id="jeVNo" readonly></div>
            </div>
            <div class="ac-fg" style="margin-bottom:14px;"><label>Narration</label><input type="text" id="jeNarration" placeholder="Description of the entry" style="width:100%"></div>

            <div class="ac-form-section"><i class="fa fa-list"></i> Line Items</div>
            <table class="ac-table" style="margin-bottom:8px;">
                <thead><tr><th>Account</th><th style="width:140px">Debit</th><th style="width:140px">Credit</th><th style="width:40px"></th></tr></thead>
                <tbody id="jeLines"></tbody>
                <tfoot>
                    <tr>
                        <td style="text-align:right;font-weight:700;">Totals:</td>
                        <td class="ac-num" id="jeTotalDr" style="font-weight:700;">0.00</td>
                        <td class="ac-num" id="jeTotalCr" style="font-weight:700;">0.00</td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            <button class="ac-btn ac-btn-ghost ac-btn-sm" onclick="AC.addJELine()"><i class="fa fa-plus"></i> Add Line</button>
            <div id="jeError" style="color:var(--ac-red);font-size:13px;margin-top:8px;display:none;"></div>
        </div>
        <div class="ac-modal-actions">
            <button class="ac-btn ac-btn-ghost" onclick="AC.closeModal('journalModal')">Cancel</button>
            <button class="ac-btn ac-btn-primary" id="btnSaveJournal" onclick="AC.saveJournalEntry()"><i class="fa fa-check"></i> Save Entry</button>
        </div>
    </div>
</div>

<!-- Income/Expense Modal -->
<div class="ac-modal-overlay" id="ieModal">
    <div class="ac-modal">
        <div class="ac-modal-header">
            <div class="ac-modal-title"><i class="fa fa-exchange"></i> <span id="ieModalTitle">Record Income</span></div>
            <button class="ac-modal-close" onclick="AC.closeModal('ieModal')">&times;</button>
        </div>
        <div class="ac-modal-body">
            <input type="hidden" id="ieFormType" value="income">
            <div class="ac-form-grid">
                <div class="ac-fg"><label>Date *</label><input type="date" id="ieFormDate"></div>
                <div class="ac-fg"><label>Amount *</label><input type="number" id="ieFormAmt" step="0.01" min="0"></div>
                <div class="ac-fg"><label>Account *</label><select id="ieFormAcct"></select></div>
                <div class="ac-fg"><label>Payment Mode</label>
                    <select id="ieFormMode"><option>Cash</option><option>Bank</option><option>UPI</option><option>Cheque</option></select>
                </div>
                <div class="ac-fg"><label>Category</label><input type="text" id="ieFormCat" placeholder="e.g. Staff Salary"></div>
                <div class="ac-fg"><label>Receipt/Ref No</label><input type="text" id="ieFormRef"></div>
                <div class="ac-fg ac-form-full"><label>Description</label><input type="text" id="ieFormDesc" style="width:100%"></div>
                <div class="ac-fg"><label>Vendor / Payee</label><input type="text" id="ieFormVendor" placeholder="Vendor/Payee name"></div>
                <div class="ac-fg"><label>Pay via Bank Account</label><select id="ieFormBank"><option value="">Cash (1010)</option></select></div>
            </div>
        </div>
        <div class="ac-modal-actions">
            <button class="ac-btn ac-btn-ghost" onclick="AC.closeModal('ieModal')">Cancel</button>
            <button class="ac-btn ac-btn-primary" id="btnSaveIE" onclick="AC.saveIE()"><i class="fa fa-check"></i> Save</button>
        </div>
    </div>
</div>

<!-- Bank Match Modal -->
<div class="ac-modal-overlay" id="matchModal">
    <div class="ac-modal" style="max-width:700px;">
        <div class="ac-modal-header">
            <div class="ac-modal-title"><i class="fa fa-link"></i> Match Transaction</div>
            <button class="ac-modal-close" onclick="AC.closeModal('matchModal')">&times;</button>
        </div>
        <div class="ac-modal-body">
            <div style="margin-bottom:14px;padding:12px;background:var(--ac-bg3);border-radius:var(--ac-r);font-size:13px;">
                <div id="matchStmtInfo"></div>
            </div>
            <div class="ac-form-section"><i class="fa fa-lightbulb-o"></i> Suggested Matches</div>
            <div class="ac-table-wrap">
                <table class="ac-table">
                    <thead><tr><th>Date</th><th>Voucher</th><th>Narration</th><th class="ac-num">Dr</th><th class="ac-num">Cr</th><th>Score</th><th></th></tr></thead>
                    <tbody id="matchSuggestions"></tbody>
                </table>
            </div>
            <div style="margin-top:12px;">
                <div class="ac-fg"><label>Or enter Ledger Entry ID manually</label>
                    <div style="display:flex;gap:8px;">
                        <input type="text" id="matchManualId" placeholder="e.g. JE_20260312..." style="flex:1;">
                        <button class="ac-btn ac-btn-primary ac-btn-sm" onclick="AC.doMatch(document.getElementById('matchManualId').value)">Match</button>
                    </div>
                </div>
            </div>
        </div>
        <div class="ac-modal-actions">
            <button class="ac-btn ac-btn-ghost" onclick="AC.closeModal('matchModal')">Cancel</button>
        </div>
    </div>
</div>

<div class="ac-toast" id="acToast"></div>

<script>
(function(){
    var BASE = '<?= base_url() ?>';
    var CSRF_NAME = '<?= $this->security->get_csrf_token_name() ?>';
    var CSRF_HASH = '<?= $this->security->get_csrf_hash() ?>';
    var ADMIN_ROLE = '<?= $admin_role ?>';
    var IS_ADMIN = ['Admin','Super Admin','School Super Admin','Our Panel'].indexOf(ADMIN_ROLE) >= 0;
    var IS_FINANCE = IS_ADMIN || <?= json_encode(has_permission('Accounting')) ?>;

    var coaCache = {}; // code → account object

    // ── Helpers ──
    function _readCsrfCookie() {
        var m = document.cookie.match('(?:^|; )' + CSRF_NAME + '=([^;]+)');
        return m ? decodeURIComponent(m[1]) : CSRF_HASH;
    }

    function post(url, data) {
        var fd = new FormData();
        fd.append(CSRF_NAME, _readCsrfCookie());
        if (data) Object.keys(data).forEach(function(k){ fd.append(k, data[k]); });
        return fetch(BASE + url, { method: 'POST', body: fd })
            .then(function(r){
                if (r.status === 403) return { status: 'error', message: 'Session expired or permission denied. Please refresh the page.' };
                if (!r.ok) return { status: 'error', message: 'Server error (' + r.status + ')' };
                return r.json();
            })
            .then(function(j){ if (j && j.csrf_hash) CSRF_HASH = j.csrf_hash; return j; })
            .catch(function(e){ toast('Network error: ' + e.message, 'error'); return { status: 'error', message: e.message }; });
    }

    function getJSON(url) {
        return fetch(BASE + url)
            .then(function(r){
                if (r.status === 403) return { status: 'error', message: 'Session expired or permission denied. Please refresh the page.' };
                if (!r.ok) return { status: 'error', message: 'Server error (' + r.status + ')' };
                return r.json();
            })
            .then(function(j){ if (j && j.csrf_hash) CSRF_HASH = j.csrf_hash; return j; })
            .catch(function(e){ toast('Network error: ' + e.message, 'error'); return { status: 'error', message: e.message }; });
    }

    function toast(msg, type) {
        var el = document.getElementById('acToast');
        el.textContent = msg; el.className = 'ac-toast ' + (type || 'success');
        setTimeout(function(){ el.classList.add('show'); }, 10);
        setTimeout(function(){ el.classList.remove('show'); }, 3000);
    }

    function fmt(n) { return Number(n || 0).toLocaleString('en-IN', {minimumFractionDigits: 2, maximumFractionDigits: 2}); }
    function esc(s) { var d = document.createElement('div'); d.textContent = s || ''; return d.innerHTML; }

    function closeModal(id) { document.getElementById(id).classList.remove('show'); }

    // Close modal when clicking backdrop (outside the modal box)
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('ac-modal-overlay') && e.target.classList.contains('show')) {
            e.target.classList.remove('show');
        }
    });

    function catBadge(cat) {
        return '<span class="ac-badge ac-badge-' + (cat || '').toLowerCase() + '">' + esc(cat) + '</span>';
    }

    // ── Role-based visibility ──
    function applyRoleVisibility() {
        var adminEls = document.querySelectorAll('.ac-role-admin');
        for (var i = 0; i < adminEls.length; i++) {
            adminEls[i].style.display = IS_ADMIN ? '' : 'none';
        }
    }

    // Tabs use clean URLs (<a> links) — no JS switching needed.
    // Each tab click triggers a full page reload with the correct active_tab from PHP.

    // ══════════════════════════════════════════════
    //  CHART OF ACCOUNTS
    // ══════════════════════════════════════════════
    function loadCoA() {
        getJSON('accounting/get_chart').then(function(r) {
            if (r.status !== 'success') return toast(r.message, 'error');
            coaCache = r.accounts || {};
            renderCoA();
            populateAccountDropdowns();
        });
    }

    function renderCoA() {
        var body = document.getElementById('coaBody');
        var accounts = coaCache;
        var codes = Object.keys(accounts).sort(function(a,b){ return a.localeCompare(b, undefined, {numeric:true}); });

        if (!codes.length) {
            body.innerHTML = '<tr><td colspan="7" class="ac-empty">'
                + '<div style="padding:20px 0">'
                + '<i class="fa fa-sitemap" style="font-size:36px;color:var(--ac-text3);display:block;margin-bottom:12px"></i>'
                + '<div style="font-size:15px;font-weight:600;color:var(--ac-text);margin-bottom:6px">No Chart of Accounts</div>'
                + '<div style="font-size:13px;color:var(--ac-text2);margin-bottom:16px;max-width:400px;margin-left:auto;margin-right:auto">'
                + 'Set up your chart of accounts to start tracking finances. Seed the standard Indian school template with 50+ accounts across Assets, Liabilities, Equity, Income, and Expenses.'
                + '</div>'
                + (IS_ADMIN ? '<button class="ac-btn ac-btn-primary" onclick="AC.seedChart()" style="margin-right:8px"><i class="fa fa-magic"></i> Seed Default Chart</button>' : '')
                + '<button class="ac-btn ac-btn-ghost" onclick="AC.showAccountModal()"><i class="fa fa-plus"></i> Add Manually</button>'
                + '</div>'
                + '</td></tr>';
            return;
        }

        var html = '';
        codes.forEach(function(code) {
            var a = accounts[code];
            var indent = a.parent_code ? (accounts[a.parent_code] && accounts[a.parent_code].parent_code ? 'ac-indent-2' : 'ac-indent-1') : '';
            var rowCls = a.is_group ? 'ac-group-row' : '';

            var curBal = Number(a.current_balance || 0);
            var curBalCls = curBal > 0 ? 'color:var(--ac-green)' : (curBal < 0 ? 'color:var(--ac-red)' : '');

            html += '<tr class="' + rowCls + '">'
                + '<td><code>' + esc(code) + '</code></td>'
                + '<td class="' + indent + '">' + (a.is_group ? '<i class="fa fa-folder-o" style="margin-right:6px;color:var(--ac-text3)"></i>' : '') + esc(a.name) + (a.is_bank ? ' <i class="fa fa-bank" style="color:var(--ac-blue);font-size:12px;margin-left:4px;" title="Bank Account"></i>' : '') + '</td>'
                + '<td>' + catBadge(a.category) + '</td>'
                + '<td style="font-size:12px;color:var(--ac-text2);">' + esc(a.sub_category || '') + '</td>'
                + '<td class="ac-num">' + fmt(a.opening_balance) + '</td>'
                + '<td class="ac-num" style="font-weight:600;' + curBalCls + '">' + fmt(curBal) + '</td>'
                + '<td>'
                + (a.is_system ? '<span style="font-size:11px;color:var(--ac-text3)">System</span>'
                    : '<button class="ac-btn ac-btn-ghost ac-btn-sm" onclick="AC.editAccount(\'' + code + '\')"><i class="fa fa-pencil"></i></button> '
                    + (IS_ADMIN ? '<button class="ac-btn ac-btn-danger ac-btn-sm" onclick="AC.deleteAccount(\'' + code + '\')"><i class="fa fa-trash"></i></button>' : ''))
                + '</td></tr>';
        });
        body.innerHTML = html;
    }

    function populateAccountDropdowns() {
        var codes = Object.keys(coaCache).sort(function(a,b){ return a.localeCompare(b, undefined, {numeric:true}); });

        // Ledger filter
        var ledgerAcct = document.getElementById('ledgerAcct');
        var prevVal = ledgerAcct.value;
        ledgerAcct.innerHTML = '<option value="">All</option>';
        codes.forEach(function(c){
            var a = coaCache[c];
            if (a.is_group) return;
            ledgerAcct.innerHTML += '<option value="' + c + '">' + c + ' - ' + esc(a.name) + '</option>';
        });
        ledgerAcct.value = prevVal;

        // IE form accounts
        var ieFormAcct = document.getElementById('ieFormAcct');
        ieFormAcct.innerHTML = '<option value="">Select Account</option>';
        codes.forEach(function(c){
            var a = coaCache[c];
            if (a.is_group) return;
            ieFormAcct.innerHTML += '<option value="' + c + '">' + c + ' - ' + esc(a.name) + '</option>';
        });

        // IE bank dropdown
        var ieFormBank = document.getElementById('ieFormBank');
        ieFormBank.innerHTML = '<option value="">Cash (1010)</option>';
        codes.forEach(function(c){
            var a = coaCache[c];
            if (a.is_bank) ieFormBank.innerHTML += '<option value="' + c + '">' + c + ' - ' + esc(a.name) + '</option>';
        });
    }

    function showAccountModal(code) {
        var isEdit = !!code;
        document.getElementById('amIsEdit').value = isEdit ? 'true' : 'false';
        document.getElementById('accountModalTitle').textContent = isEdit ? 'Edit Account' : 'Add Account';

        if (isEdit && coaCache[code]) {
            var a = coaCache[code];
            document.getElementById('amCode').value = a.code; document.getElementById('amCode').readOnly = true;
            document.getElementById('amName').value = a.name;
            document.getElementById('amCategory').value = a.category;
            document.getElementById('amSubCat').value = a.sub_category || '';
            document.getElementById('amParent').value = a.parent_code || '';
            document.getElementById('amOpenBal').value = a.opening_balance || 0;
            document.getElementById('amDesc').value = a.description || '';
            document.getElementById('amIsGroup').checked = !!a.is_group;
            document.getElementById('amIsBank').checked = !!a.is_bank;
            if (a.bank_details) {
                document.getElementById('amBankName').value = a.bank_details.bank_name || '';
                document.getElementById('amBranch').value = a.bank_details.branch || '';
                document.getElementById('amAccNo').value = a.bank_details.account_no || '';
                document.getElementById('amIfsc').value = a.bank_details.ifsc || '';
            }
        } else {
            ['amCode','amName','amSubCat','amParent','amDesc','amBankName','amBranch','amAccNo','amIfsc'].forEach(function(id){ document.getElementById(id).value = ''; });
            document.getElementById('amCode').readOnly = false;
            document.getElementById('amCategory').value = '';
            document.getElementById('amOpenBal').value = '0';
            document.getElementById('amIsGroup').checked = false;
            document.getElementById('amIsBank').checked = false;
        }
        document.getElementById('amBankFields').style.display = document.getElementById('amIsBank').checked ? 'block' : 'none';
        document.getElementById('accountModal').classList.add('show');
    }

    document.getElementById('amIsBank').addEventListener('change', function(){
        document.getElementById('amBankFields').style.display = this.checked ? 'block' : 'none';
    });

    function saveAccount() {
        var btn = document.getElementById('btnSaveAccount');
        btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';
        post('accounting/save_account', {
            code: document.getElementById('amCode').value,
            name: document.getElementById('amName').value,
            category: document.getElementById('amCategory').value,
            sub_category: document.getElementById('amSubCat').value,
            parent_code: document.getElementById('amParent').value,
            opening_balance: document.getElementById('amOpenBal').value,
            description: document.getElementById('amDesc').value,
            is_group: document.getElementById('amIsGroup').checked ? 'true' : 'false',
            is_bank: document.getElementById('amIsBank').checked ? 'true' : 'false',
            is_edit: document.getElementById('amIsEdit').value,
            bank_name: document.getElementById('amBankName').value,
            branch: document.getElementById('amBranch').value,
            account_no: document.getElementById('amAccNo').value,
            ifsc: document.getElementById('amIfsc').value,
        }).then(function(r){
            btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Save';
            if (r.status !== 'success') return toast(r.message, 'error');
            toast(r.message); closeModal('accountModal'); loadCoA();
        }).catch(function(){ btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Save'; });
    }

    function editAccount(code) { showAccountModal(code); }

    function deleteAccount(code) {
        if (!confirm('Delete account ' + code + '?')) return;
        post('accounting/delete_account', { code: code }).then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');
            toast(r.message); loadCoA();
        });
    }

    function seedChart() {
        if (!confirm('Seed the standard Indian school chart of accounts?\n\nThis will add ~55 default accounts across 5 categories:\n• Assets (Cash, Bank, Receivables, Fixed Assets)\n• Liabilities (Payables, Statutory, Loans)\n• Equity (Capital, Retained Surplus)\n• Income (Fees, Donations, Other)\n• Expenses (Staff, Admin, Educational, Utilities)\n\nExisting accounts will NOT be changed or overwritten.')) return;
        var btn = document.getElementById('btnSeedChart');
        if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Seeding...'; }
        post('accounting/seed_default_chart').then(function(r){
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-magic"></i> Seed Defaults'; }
            if (r.status !== 'success') return toast(r.message, 'error');
            var msg = r.message;
            if (r.added && r.added.length) msg += '\nCreated: ' + r.added.join(', ');
            toast(msg, 'success'); loadCoA();
        }).catch(function(){
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-magic"></i> Seed Defaults'; }
            toast('Failed to seed chart', 'error');
        });
    }

    // ══════════════════════════════════════════════
    //  VOUCHER TYPE DISPLAY HELPER
    // ══════════════════════════════════════════════
    function formatVoucherType(type, source, narration) {
        var t = type || 'Journal';
        var badge = '<span class="ac-vtype-badge">' + esc(t) + '</span>';
        if (t === 'Journal') {
            var src = (source || '').toLowerCase();
            var nar = (narration || '').toLowerCase();
            if (src === 'hr_payroll' || src === 'payroll') {
                if (nar.indexOf('payment') >= 0 || nar.indexOf('disbursement') >= 0) {
                    badge += '<span class="ac-vtype-sub">Salary Payment</span>';
                } else if (nar.indexOf('accrual') >= 0) {
                    badge += '<span class="ac-vtype-sub">Salary Accrual</span>';
                } else {
                    badge += '<span class="ac-vtype-sub">Payroll</span>';
                }
            } else if (nar.indexOf('salary') >= 0) {
                if (nar.indexOf('payment') >= 0) {
                    badge += '<span class="ac-vtype-sub">Salary Payment</span>';
                } else {
                    badge += '<span class="ac-vtype-sub">Payroll</span>';
                }
            } else if (src === 'income') {
                badge += '<span class="ac-vtype-sub">Income</span>';
            } else if (src === 'expense') {
                badge += '<span class="ac-vtype-sub">Expense</span>';
            } else if (nar.indexOf('fee') >= 0) {
                badge += '<span class="ac-vtype-sub">Fee Collection</span>';
            }
        }
        return badge;
    }

    // ══════════════════════════════════════════════
    //  JOURNAL ENTRIES
    // ══════════════════════════════════════════════
    var ledgerOffset = 0, ledgerLimit = 100, ledgerHasMore = false;

    function loadLedger(append) {
        if (!append) ledgerOffset = 0;
        post('accounting/get_ledger_entries', {
            date_from: document.getElementById('ledgerFrom').value,
            date_to: document.getElementById('ledgerTo').value,
            account_code: document.getElementById('ledgerAcct').value,
            voucher_type: document.getElementById('ledgerVType').value,
            limit: ledgerLimit,
            offset: ledgerOffset,
        }).then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');
            var body = document.getElementById('ledgerBody');
            if (!append) body.innerHTML = '';

            if (!r.entries.length && !append) {
                body.innerHTML = '<tr><td colspan="8" class="ac-empty">No entries found.</td></tr>';
                document.getElementById('ledgerPagination').style.display = 'none';
                return;
            }

            var html = '';
            r.entries.forEach(function(e){
                var st = e.is_finalized ? '<span class="ac-badge ac-badge-finalized">Finalized</span>' : '<span class="ac-badge ac-badge-draft">Draft</span>';
                html += '<tr>'
                    + '<td>' + esc(e.date) + '</td>'
                    + '<td><code>' + esc(e.voucher_no) + '</code></td>'
                    + '<td>' + formatVoucherType(e.voucher_type, e.source, e.narration) + '</td>'
                    + '<td>' + esc(e.narration) + '</td>'
                    + '<td class="ac-num ac-dr">' + fmt(e.total_dr) + '</td>'
                    + '<td class="ac-num ac-cr">' + fmt(e.total_cr) + '</td>'
                    + '<td>' + st + '</td>'
                    + '<td>'
                    + (!e.is_finalized ? '<button class="ac-btn ac-btn-danger ac-btn-sm" onclick="AC.deleteJE(\'' + e.id + '\')"><i class="fa fa-trash"></i></button> ' : '')
                    + (!e.is_finalized ? '<button class="ac-btn ac-btn-ghost ac-btn-sm" onclick="AC.finalizeJE(\'' + e.id + '\')"><i class="fa fa-lock"></i></button>' : '')
                    + '</td></tr>';

                // Show line items
                (e.lines || []).forEach(function(l){
                    html += '<tr style="background:var(--ac-bg);font-size:12px;"><td></td><td colspan="2" style="padding-left:24px;">'
                        + '<code>' + esc(l.account_code) + '</code> ' + esc(l.account_name) + '</td><td>' + esc(l.narration || '') + '</td>'
                        + '<td class="ac-num">' + (l.dr > 0 ? fmt(l.dr) : '') + '</td>'
                        + '<td class="ac-num">' + (l.cr > 0 ? fmt(l.cr) : '') + '</td><td></td><td></td></tr>';
                });
            });
            body.innerHTML += html;

            ledgerHasMore = r.has_more || false;
            ledgerOffset += r.entries.length;

            var total = r.total || r.entries.length;
            var pag = document.getElementById('ledgerPagination');
            pag.style.display = (total > 0) ? 'block' : 'none';
            document.getElementById('ledgerCount').textContent = 'Showing ' + Math.min(ledgerOffset, total) + ' of ' + total;
            document.getElementById('ledgerLoadMore').style.display = ledgerHasMore ? 'inline-flex' : 'none';
        });
    }

    function loadMoreLedger() { loadLedger(true); }

    function showJournalModal() {
        document.getElementById('jeDate').value = new Date().toISOString().slice(0, 10);
        document.getElementById('jeVType').value = 'Journal';
        document.getElementById('jeNarration').value = '';
        document.getElementById('jeLines').innerHTML = '';
        document.getElementById('jeError').style.display = 'none';
        addJELine(); addJELine();

        getJSON('accounting/get_next_voucher_no?type=Journal').then(function(r){
            document.getElementById('jeVNo').value = (r && r.voucher_no) || '';
        });

        document.getElementById('journalModal').classList.add('show');
    }

    // Update voucher number when type changes
    document.getElementById('jeVType').addEventListener('change', function(){
        getJSON('accounting/get_next_voucher_no?type=' + this.value).then(function(r){
            document.getElementById('jeVNo').value = (r && r.voucher_no) || '';
        });
    });

    function addJELine() {
        var codes = Object.keys(coaCache).sort(function(a,b){ return a.localeCompare(b, undefined, {numeric:true}); });
        var opts = '<option value="">Select Account</option>';
        codes.forEach(function(c){
            var a = coaCache[c]; if (a.is_group) return;
            opts += '<option value="' + c + '">' + c + ' - ' + esc(a.name) + '</option>';
        });

        var tr = document.createElement('tr');
        tr.innerHTML = '<td><select class="je-acct">' + opts + '</select></td>'
            + '<td><input type="number" class="je-dr" step="0.01" min="0" value="" placeholder="0.00"></td>'
            + '<td><input type="number" class="je-cr" step="0.01" min="0" value="" placeholder="0.00"></td>'
            + '<td><button class="ac-btn ac-btn-danger ac-btn-sm" onclick="this.closest(\'tr\').remove();AC.updateJETotals();" style="padding:5px 8px;"><i class="fa fa-times"></i></button></td>';
        document.getElementById('jeLines').appendChild(tr);

        // Auto-clear other field on input
        tr.querySelector('.je-dr').addEventListener('input', function(){ if (this.value) tr.querySelector('.je-cr').value = ''; updateJETotals(); });
        tr.querySelector('.je-cr').addEventListener('input', function(){ if (this.value) tr.querySelector('.je-dr').value = ''; updateJETotals(); });
    }

    function updateJETotals() {
        var dr = 0, cr = 0;
        document.querySelectorAll('#jeLines tr').forEach(function(tr){
            dr += parseFloat(tr.querySelector('.je-dr').value || 0);
            cr += parseFloat(tr.querySelector('.je-cr').value || 0);
        });
        document.getElementById('jeTotalDr').textContent = fmt(dr);
        document.getElementById('jeTotalCr').textContent = fmt(cr);
        var errEl = document.getElementById('jeError');
        if (Math.abs(dr - cr) > 0.01 && dr > 0 && cr > 0) {
            errEl.textContent = 'Debit (' + fmt(dr) + ') does not equal Credit (' + fmt(cr) + ')';
            errEl.style.display = 'block';
        } else {
            errEl.style.display = 'none';
        }
    }

    function saveJournalEntry() {
        var lines = [];
        document.querySelectorAll('#jeLines tr').forEach(function(tr){
            var code = tr.querySelector('.je-acct').value;
            if (!code) return;
            var acctName = coaCache[code] ? coaCache[code].name : code;
            lines.push({
                account_code: code,
                account_name: acctName,
                dr: parseFloat(tr.querySelector('.je-dr').value || 0),
                cr: parseFloat(tr.querySelector('.je-cr').value || 0),
            });
        });

        var btn = document.getElementById('btnSaveJournal');
        btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';
        post('accounting/save_journal_entry', {
            date: document.getElementById('jeDate').value,
            voucher_type: document.getElementById('jeVType').value,
            narration: document.getElementById('jeNarration').value,
            lines: JSON.stringify(lines),
        }).then(function(r){
            btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Save Entry';
            if (r.status !== 'success') return toast(r.message, 'error');
            toast(r.message); closeModal('journalModal'); loadLedger();
        }).catch(function(){ btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Save Entry'; });
    }

    function deleteJE(id) {
        if (!confirm('Delete this journal entry?')) return;
        post('accounting/delete_journal_entry', { entry_id: id }).then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');
            toast(r.message); loadLedger();
        });
    }

    function finalizeJE(id) {
        if (!confirm('Finalize this entry? It cannot be edited or deleted after.')) return;
        post('accounting/finalize_entry', { entry_id: id }).then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');
            toast(r.message); loadLedger();
        });
    }

    // ══════════════════════════════════════════════
    //  INCOME & EXPENSE
    // ══════════════════════════════════════════════
    var ieOffset = 0, ieLimit = 100, ieHasMore = false;

    function loadIE(append) {
        if (!append) ieOffset = 0;
        post('accounting/get_income_expenses', {
            type: document.getElementById('ieType').value,
            date_from: document.getElementById('ieFrom').value,
            date_to: document.getElementById('ieTo').value,
            limit: ieLimit,
            offset: ieOffset,
        }).then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');
            var body = document.getElementById('ieBody');
            if (!append) body.innerHTML = '';
            var totalInc = 0, totalExp = 0;

            if (!r.records.length && !append) {
                body.innerHTML = '<tr><td colspan="7" class="ac-empty">No records found.</td></tr>';
                document.getElementById('iePagination').style.display = 'none';
            }
            else {
                var html = '';
                r.records.forEach(function(rec){
                    var acctName = coaCache[rec.account_code] ? coaCache[rec.account_code].name : rec.account_code;
                    var typeBadge = rec.type === 'income'
                        ? '<span class="ac-badge ac-badge-income">Income</span>'
                        : '<span class="ac-badge ac-badge-expense">Expense</span>';
                    if (rec.type === 'income') totalInc += rec.amount; else totalExp += rec.amount;

                    html += '<tr>'
                        + '<td>' + esc(rec.date) + '</td>'
                        + '<td>' + typeBadge + '</td>'
                        + '<td>' + esc(rec.account_code) + ' - ' + esc(acctName) + '</td>'
                        + '<td>' + esc(rec.description) + '</td>'
                        + '<td>' + esc(rec.payment_mode || '') + '</td>'
                        + '<td class="ac-num">' + fmt(rec.amount) + '</td>'
                        + '<td><button class="ac-btn ac-btn-danger ac-btn-sm" onclick="AC.deleteIE(\'' + rec.id + '\')"><i class="fa fa-trash"></i></button></td>'
                        + '</tr>';
                });
                body.innerHTML += html;

                ieHasMore = r.has_more || false;
                ieOffset += r.records.length;

                var total = r.total || r.records.length;
                var pag = document.getElementById('iePagination');
                pag.style.display = (total > 0) ? 'block' : 'none';
                document.getElementById('ieCount').textContent = 'Showing ' + Math.min(ieOffset, total) + ' of ' + total;
                document.getElementById('ieLoadMore').style.display = ieHasMore ? 'inline-flex' : 'none';
            }

            if (!append) {
                // Load full summary from dedicated endpoint
                post('accounting/get_income_expense_summary').then(function(sr){
                    if (sr.status !== 'success') return;
                    var sumInc = 0, sumExp = 0;
                    var months = sr.summary || {};
                    Object.keys(months).forEach(function(m){
                        sumInc += months[m].income || 0;
                        sumExp += months[m].expense || 0;
                    });
                    document.getElementById('ieSummary').innerHTML =
                        '<div class="ac-stat"><div class="ac-stat-label">Total Income</div><div class="ac-stat-value" style="color:var(--ac-green)">' + fmt(sumInc) + '</div></div>'
                        + '<div class="ac-stat"><div class="ac-stat-label">Total Expenses</div><div class="ac-stat-value" style="color:var(--ac-red)">' + fmt(sumExp) + '</div></div>'
                        + '<div class="ac-stat"><div class="ac-stat-label">Net</div><div class="ac-stat-value">' + fmt(sumInc - sumExp) + '</div></div>';
                });
            }
        });
    }

    function loadMoreIE() { loadIE(true); }

    function showIEModal(type) {
        document.getElementById('ieFormType').value = type;
        document.getElementById('ieModalTitle').textContent = type === 'income' ? 'Record Income' : 'Record Expense';
        document.getElementById('ieFormDate').value = new Date().toISOString().slice(0, 10);
        document.getElementById('ieFormMode').value = 'Cash';
        ['ieFormAmt','ieFormCat','ieFormRef','ieFormDesc','ieFormVendor'].forEach(function(id){ document.getElementById(id).value = ''; });

        var codes = Object.keys(coaCache).sort(function(a,b){ return a.localeCompare(b, undefined, {numeric:true}); });

        // Filter accounts by type
        var sel = document.getElementById('ieFormAcct');
        sel.innerHTML = '<option value="">Select Account</option>';
        var filterCat = type === 'income' ? 'Income' : 'Expense';
        codes.forEach(function(c){
            var a = coaCache[c]; if (a.is_group) return;
            if (a.category === filterCat) {
                sel.innerHTML += '<option value="' + c + '">' + c + ' - ' + esc(a.name) + '</option>';
            }
        });

        // Refresh bank account dropdown
        var bankSel = document.getElementById('ieFormBank');
        bankSel.innerHTML = '<option value="">Cash (1010)</option>';
        codes.forEach(function(c){
            var a = coaCache[c];
            if (a.is_bank) bankSel.innerHTML += '<option value="' + c + '">' + c + ' - ' + esc(a.name) + '</option>';
        });

        document.getElementById('ieModal').classList.add('show');
    }

    function saveIE() {
        var btn = document.getElementById('btnSaveIE');
        btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';
        post('accounting/save_income_expense', {
            type: document.getElementById('ieFormType').value,
            date: document.getElementById('ieFormDate').value,
            amount: document.getElementById('ieFormAmt').value,
            account_code: document.getElementById('ieFormAcct').value,
            payment_mode: document.getElementById('ieFormMode').value,
            bank_account_code: document.getElementById('ieFormBank').value,
            category: document.getElementById('ieFormCat').value,
            receipt_no: document.getElementById('ieFormRef').value,
            description: document.getElementById('ieFormDesc').value,
            vendor: document.getElementById('ieFormVendor').value,
        }).then(function(r){
            btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Save';
            if (r.status !== 'success') return toast(r.message, 'error');
            toast(r.message); closeModal('ieModal'); loadIE();
        }).catch(function(){ btn.disabled = false; btn.innerHTML = '<i class="fa fa-check"></i> Save'; });
    }

    function deleteIE(id) {
        if (!confirm('Delete this record and its journal entry?')) return;
        post('accounting/delete_income_expense', { id: id }).then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');
            toast(r.message); loadIE();
        });
    }

    // ══════════════════════════════════════════════
    //  CASH BOOK
    // ══════════════════════════════════════════════
    function populateCashBookAccounts() {
        if (!Object.keys(coaCache).length) {
            getJSON('accounting/get_chart').then(function(r){
                if (r.status === 'success') { coaCache = r.accounts || {}; populateAccountDropdowns(); _fillCBDropdown(); }
            });
        } else { _fillCBDropdown(); }
    }

    function _fillCBDropdown() {
        var sel = document.getElementById('cbAccount');
        sel.innerHTML = '';
        Object.keys(coaCache).sort().forEach(function(c){
            var a = coaCache[c];
            if (a.is_group) return;
            if (c === '1010' || a.is_bank || (a.sub_category||'').toLowerCase().indexOf('cash') >= 0) {
                sel.innerHTML += '<option value="' + c + '">' + c + ' - ' + esc(a.name) + '</option>';
            }
        });
    }

    function loadCashBook() {
        var selCode = document.getElementById('cbAccount').value;
        if (!selCode) return toast('Select an account first.', 'error');
        post('accounting/get_cash_book', {
            account_code: selCode,
            date_from: document.getElementById('cbFrom').value,
            date_to: document.getElementById('cbTo').value,
        }).then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');

            // Show account header
            var acct = r.account || {};
            var hdr = document.getElementById('cbAccountHeader');
            if (hdr) {
                hdr.style.display = 'flex';
                document.getElementById('cbAcctName').textContent = (acct.name || selCode);
                document.getElementById('cbAcctCode').textContent = 'Account ' + (r.account_code || selCode)
                    + ' | ' + (acct.category || '') + ' | Normal side: ' + (acct.normal_side || 'Dr');
            }

            // Stats
            document.getElementById('cbStats').innerHTML =
                '<div class="ac-stat"><div class="ac-stat-label">Opening Balance</div><div class="ac-stat-value">' + fmt(r.opening_balance) + '</div></div>'
                + '<div class="ac-stat"><div class="ac-stat-label">Total Received (Dr)</div><div class="ac-stat-value" style="color:var(--ac-green)">' + fmt(r.total_dr) + '</div></div>'
                + '<div class="ac-stat"><div class="ac-stat-label">Total Paid (Cr)</div><div class="ac-stat-value" style="color:var(--ac-red)">' + fmt(r.total_cr) + '</div></div>'
                + '<div class="ac-stat"><div class="ac-stat-label">Closing Balance</div><div class="ac-stat-value">' + fmt(r.closing_balance) + '</div></div>';

            var body = document.getElementById('cbBody');
            var foot = document.getElementById('cbFoot');
            if (!r.transactions.length) {
                body.innerHTML = '<tr><td colspan="8" class="ac-empty">No transactions found for this account in the selected period.</td></tr>';
                if (foot) foot.style.display = 'none';
                return;
            }

            var html = '';
            r.transactions.forEach(function(t){
                html += '<tr>'
                    + '<td>' + esc(t.date) + '</td>'
                    + '<td><code style="font-size:11px">' + esc(t.voucher_no) + '</code></td>'
                    + '<td>' + formatVoucherType(t.type, t.source, t.narration) + '</td>'
                    + '<td style="font-size:12px;color:var(--ac-text2)">' + esc(t.contra || '-') + '</td>'
                    + '<td>' + esc(t.narration) + '</td>'
                    + '<td class="ac-num ac-dr">' + (t.dr > 0 ? fmt(t.dr) : '') + '</td>'
                    + '<td class="ac-num ac-cr">' + (t.cr > 0 ? fmt(t.cr) : '') + '</td>'
                    + '<td class="ac-num" style="font-weight:600">' + fmt(t.balance) + '</td>'
                    + '</tr>';
            });
            body.innerHTML = html;

            // Footer totals
            if (foot) {
                foot.style.display = '';
                document.getElementById('cbTotalDr').textContent = fmt(r.total_dr);
                document.getElementById('cbTotalCr').textContent = fmt(r.total_cr);
                document.getElementById('cbClosingBal').textContent = fmt(r.closing_balance);
            }
        });
    }

    // ══════════════════════════════════════════════
    //  BANK RECONCILIATION
    // ══════════════════════════════════════════════
    function loadBankAccounts() {
        getJSON('accounting/get_bank_accounts').then(function(r){
            if (r.status !== 'success') return;
            var sel = document.getElementById('brAccount');
            sel.innerHTML = '';
            (r.banks || []).forEach(function(b){
                sel.innerHTML += '<option value="' + b.code + '">' + esc(b.name) + '</option>';
            });
        });
    }

    function loadBankRecon() {
        var code = document.getElementById('brAccount').value;
        if (!code) return toast('Select a bank account.', 'error');

        // Load statement + summary in parallel
        post('accounting/get_bank_statement', {
            account_code: code,
            date_from: document.getElementById('brFrom').value,
            date_to: document.getElementById('brTo').value,
        }).then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');
            var body = document.getElementById('brBody');
            if (!r.items.length) { body.innerHTML = '<tr><td colspan="7" class="ac-empty">No statement entries. Import a CSV.</td></tr>'; return; }

            var html = '';
            r.items.forEach(function(item){
                var statusBadge = item.status === 'matched'
                    ? '<span class="ac-badge ac-badge-matched">Matched</span>'
                    : '<span class="ac-badge ac-badge-unmatched">Unmatched</span>';
                html += '<tr>'
                    + '<td>' + esc(item.statement_date) + '</td>'
                    + '<td>' + esc(item.description) + '</td>'
                    + '<td>' + esc(item.reference || '') + '</td>'
                    + '<td class="ac-num">' + (item.debit > 0 ? fmt(item.debit) : '') + '</td>'
                    + '<td class="ac-num">' + (item.credit > 0 ? fmt(item.credit) : '') + '</td>'
                    + '<td>' + statusBadge + '</td>'
                    + '<td>'
                    + (item.status === 'unmatched'
                        ? '<button class="ac-btn ac-btn-ghost ac-btn-sm" onclick="AC.matchPrompt(\'' + code + '\',\'' + item.id + '\')"><i class="fa fa-link"></i> Match</button>'
                        : '<button class="ac-btn ac-btn-ghost ac-btn-sm" onclick="AC.unmatchTxn(\'' + code + '\',\'' + item.id + '\')"><i class="fa fa-chain-broken"></i></button>')
                    + '</td>'
                    + '</tr>';
            });
            body.innerHTML = html;
        });

        post('accounting/get_recon_summary', { account_code: code }).then(function(r){
            if (r.status !== 'success') return;
            document.getElementById('brStats').innerHTML =
                '<div class="ac-stat"><div class="ac-stat-label">Bank Balance</div><div class="ac-stat-value">' + fmt(r.bank_balance) + '</div></div>'
                + '<div class="ac-stat"><div class="ac-stat-label">Book Balance</div><div class="ac-stat-value">' + fmt(r.book_balance) + '</div></div>'
                + '<div class="ac-stat"><div class="ac-stat-label">Difference</div><div class="ac-stat-value" style="color:' + (Math.abs(r.difference) < 0.01 ? 'var(--ac-green)' : 'var(--ac-red)') + '">' + fmt(r.difference) + '</div></div>'
                + '<div class="ac-stat"><div class="ac-stat-label">Unmatched</div><div class="ac-stat-value">' + r.unmatched + '</div></div>';
        });
    }

    function showImportCSV() {
        var code = document.getElementById('brAccount').value;
        if (!code) return toast('Select a bank account first.', 'error');

        var input = document.createElement('input');
        input.type = 'file'; input.accept = '.csv';
        input.onchange = function(){
            var fd = new FormData();
            fd.append(CSRF_NAME, CSRF_HASH);
            fd.append('account_code', code);
            fd.append('csv_file', input.files[0]);
            fetch(BASE + 'accounting/import_bank_statement', { method: 'POST', body: fd })
                .then(function(r){ return r.json(); })
                .then(function(r){
                    if (r && r.csrf_hash) CSRF_HASH = r.csrf_hash;
                    if (r.status !== 'success') return toast(r.message, 'error');
                    toast(r.message); loadBankRecon();
                });
        };
        input.click();
    }

    // ── Bank Match with suggestions ──
    var _matchCode = '', _matchReconId = '';

    function matchPrompt(code, reconId) {
        _matchCode = code;
        _matchReconId = reconId;

        document.getElementById('matchStmtInfo').textContent = 'Loading...';
        document.getElementById('matchSuggestions').innerHTML = '';
        document.getElementById('matchManualId').value = '';

        post('accounting/suggest_matches', { account_code: code, recon_id: reconId }).then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');

            document.getElementById('matchStmtInfo').textContent = 'Select a matching ledger entry for statement item';

            var body = document.getElementById('matchSuggestions');
            if (!(r.suggestions || []).length) {
                body.innerHTML = '<tr><td colspan="7" style="text-align:center;color:var(--ac-text3);padding:12px;">No matching entries found. Enter ID manually below.</td></tr>';
            } else {
                var html = '';
                r.suggestions.forEach(function(s){
                    var scoreBadge = s.score >= 100 ? 'ac-badge-income' : (s.score >= 50 ? 'ac-badge-expense' : 'ac-badge-draft');
                    html += '<tr>'
                        + '<td>' + esc(s.date) + '</td>'
                        + '<td><code>' + esc(s.voucher_no) + '</code></td>'
                        + '<td>' + esc(s.narration) + '</td>'
                        + '<td class="ac-num">' + (s.dr > 0 ? fmt(s.dr) : '') + '</td>'
                        + '<td class="ac-num">' + (s.cr > 0 ? fmt(s.cr) : '') + '</td>'
                        + '<td><span class="ac-badge ' + scoreBadge + '">' + s.score + '%</span></td>'
                        + '<td><button class="ac-btn ac-btn-primary ac-btn-sm" onclick="AC.doMatch(\'' + esc(s.entry_id) + '\')"><i class="fa fa-check"></i></button></td>'
                        + '</tr>';
                });
                body.innerHTML = html;
            }
            document.getElementById('matchModal').classList.add('show');
        });
    }

    function doMatch(ledgerId) {
        if (!ledgerId) return toast('Enter a ledger entry ID.', 'error');
        post('accounting/match_transaction', { account_code: _matchCode, recon_id: _matchReconId, ledger_id: ledgerId }).then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');
            toast(r.message); closeModal('matchModal'); loadBankRecon();
        });
    }

    function unmatchTxn(code, reconId) {
        if (!confirm('Unmatch this transaction?')) return;
        post('accounting/unmatch_transaction', { account_code: code, recon_id: reconId }).then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');
            toast(r.message); loadBankRecon();
        });
    }

    // ══════════════════════════════════════════════
    //  REPORTS
    // ══════════════════════════════════════════════
    function generateReport() {
        var type = document.getElementById('rptType').value;
        var container = document.getElementById('rptOutput');
        container.innerHTML = '<p class="ac-empty">Generating...</p>';
        document.getElementById('btnExportXl').style.display = 'none';
        document.getElementById('btnExportPdf').style.display = 'none';

        post('accounting/' + type, {
            as_of_date: document.getElementById('rptFrom').value,
            date_from: document.getElementById('rptFrom').value,
            date_to: document.getElementById('rptTo').value,
        }).then(function(r){
            if (r.status !== 'success') return container.innerHTML = '<p style="color:var(--ac-red);">' + esc(r.message) + '</p>';

            if (type === 'day_book') renderDayBook(r, container);
            else if (type === 'trial_balance') renderTrialBalance(r, container);
            else if (type === 'profit_loss') renderProfitLoss(r, container);
            else if (type === 'balance_sheet') renderBalanceSheet(r, container);
            else if (type === 'cash_flow') renderCashFlow(r, container);

            // Show export buttons after successful generation
            document.getElementById('btnExportXl').style.display = '';
            document.getElementById('btnExportPdf').style.display = '';
        });
    }

    function exportReport(format) {
        var type = document.getElementById('rptType').value;
        var from = document.getElementById('rptFrom').value;
        var to   = document.getElementById('rptTo').value;
        var endpoint = (format === 'pdf') ? 'accounting/export_pdf' : 'accounting/export_excel';
        var url = BASE + endpoint + '?type=' + encodeURIComponent(type)
            + '&date_from=' + encodeURIComponent(from)
            + '&date_to=' + encodeURIComponent(to);
        window.open(url, '_blank');
    }

    function _dateSub(r) {
        var parts = [];
        if (r.date_from) parts.push('From: ' + r.date_from);
        if (r.date_to) parts.push('To: ' + r.date_to);
        if (!parts.length) parts.push('All transactions (no date filter)');
        return '<p style="font-size:12px;color:var(--ac-text3);margin:-8px 0 14px;"><i class="fa fa-calendar-o" style="margin-right:4px"></i>' + parts.join(' &nbsp;|&nbsp; ') + '</p>';
    }

    function renderDayBook(r, el) {
        var entries = r.entries || [];
        var html = '<h3 style="margin:0 0 4px;">Day Book</h3>' + _dateSub(r);
        html += '<div style="font:400 12px/1.4 var(--font-m);color:var(--ac-text3);margin-bottom:14px">'
            + entries.length + ' entries | Total Dr: <strong style="color:var(--ac-green)">' + fmt(r.total_dr) + '</strong>'
            + ' | Total Cr: <strong style="color:var(--ac-red)">' + fmt(r.total_cr) + '</strong></div>';

        if (!entries.length) {
            el.innerHTML = html + '<div style="padding:30px;text-align:center;color:var(--ac-text3)"><i class="fa fa-inbox" style="font-size:24px;display:block;margin-bottom:8px"></i>No entries in this period.</div>';
            return;
        }

        // Source badge helper
        function _src(s, sys) {
            var labels = {income:'Receipt',expense:'Payment',HR_Payroll:'Payroll',manual:'Manual',fees:'Fees'};
            var label = labels[s]||s||'Manual';
            var bg = sys ? 'rgba(15,118,110,.1)' : 'var(--ac-bg3)';
            var fg = sys ? 'var(--ac-primary)' : 'var(--ac-text3)';
            return '<span style="font-size:9px;padding:2px 6px;border-radius:3px;background:'+bg+';color:'+fg+';font-weight:600">'+label+'</span>';
        }

        // Voucher type badge
        function _vtype(t) {
            var colors = {Journal:'var(--ac-primary)',Receipt:'var(--ac-green)',Payment:'var(--ac-red)',Contra:'var(--ac-amber)'};
            var c = colors[t] || 'var(--ac-text3)';
            return '<span style="font-size:10px;padding:2px 8px;border-radius:4px;border:1px solid '+c+';color:'+c+';font-weight:600">'+esc(t)+'</span>';
        }

        html += '<table class="ac-table" id="dayBookTable">'
            + '<thead><tr><th>Date</th><th>Voucher</th><th>Type</th><th>Source</th><th>Narration</th>'
            + '<th class="ac-num">Debit</th><th class="ac-num">Credit</th><th></th></tr></thead><tbody>';

        entries.forEach(function(e, idx) {
            var finBadge = e.is_finalized ? ' <i class="fa fa-lock" style="font-size:9px;color:var(--ac-text3)" title="Finalized"></i>' : '';
            html += '<tr class="db-row" data-idx="'+idx+'" style="cursor:pointer">'
                + '<td>' + esc(e.date) + '</td>'
                + '<td><code style="font-size:11px">' + esc(e.voucher_no) + '</code>' + finBadge + '</td>'
                + '<td>' + _vtype(e.voucher_type) + '</td>'
                + '<td>' + _src(e.source, e.is_system) + '</td>'
                + '<td style="max-width:280px">' + esc(e.narration) + '</td>'
                + '<td class="ac-num ac-dr" style="font-weight:600">' + fmt(e.total_dr) + '</td>'
                + '<td class="ac-num ac-cr" style="font-weight:600">' + fmt(e.total_cr) + '</td>'
                + '<td><i class="fa fa-chevron-down" style="color:var(--ac-text3);font-size:11px;transition:transform .2s"></i></td>'
                + '</tr>';

            // Expandable line items row (hidden by default)
            html += '<tr class="db-detail" data-idx="'+idx+'" style="display:none">'
                + '<td colspan="8" style="padding:0 14px 12px 40px;background:var(--ac-bg3)">'
                + '<table style="width:100%;font-size:12px;border-collapse:collapse;margin-top:6px">'
                + '<thead><tr style="border-bottom:1px solid var(--ac-border)">'
                + '<th style="text-align:left;padding:4px 8px;font-size:11px;color:var(--ac-text3);font-weight:600">Account</th>'
                + '<th style="text-align:right;padding:4px 8px;font-size:11px;color:var(--ac-text3);font-weight:600;width:120px">Debit</th>'
                + '<th style="text-align:right;padding:4px 8px;font-size:11px;color:var(--ac-text3);font-weight:600;width:120px">Credit</th>'
                + '</tr></thead><tbody>';

            (e.lines || []).forEach(function(ln) {
                html += '<tr style="border-bottom:1px solid var(--ac-border)">'
                    + '<td style="padding:5px 8px"><code style="font-size:11px;margin-right:6px;color:var(--ac-text3)">' + esc(ln.account_code) + '</code>' + esc(ln.account_name) + '</td>'
                    + '<td style="text-align:right;padding:5px 8px;font-family:var(--font-m);color:var(--ac-green)">' + (ln.dr > 0 ? fmt(ln.dr) : '') + '</td>'
                    + '<td style="text-align:right;padding:5px 8px;font-family:var(--font-m);color:var(--ac-red)">' + (ln.cr > 0 ? fmt(ln.cr) : '') + '</td>'
                    + '</tr>';
            });

            html += '</tbody></table></td></tr>';
        });

        html += '</tbody><tfoot><tr>'
            + '<td colspan="5" style="text-align:right;font-weight:700">Totals</td>'
            + '<td class="ac-num ac-dr" style="font-weight:700">' + fmt(r.total_dr) + '</td>'
            + '<td class="ac-num ac-cr" style="font-weight:700">' + fmt(r.total_cr) + '</td>'
            + '<td></td></tr></tfoot></table>';

        // Balance check
        var diff = Math.abs(r.total_dr - r.total_cr);
        if (diff > 0.01) {
            html += '<p style="color:var(--ac-red);margin-top:8px;font-size:12px"><i class="fa fa-exclamation-triangle"></i> Imbalance detected: ' + fmt(diff) + '</p>';
        }

        el.innerHTML = html;

        // Toggle detail rows on click
        el.querySelectorAll('.db-row').forEach(function(row) {
            row.addEventListener('click', function() {
                var idx = this.getAttribute('data-idx');
                var detail = el.querySelector('.db-detail[data-idx="'+idx+'"]');
                var icon = this.querySelector('.fa-chevron-down,.fa-chevron-up');
                if (detail.style.display === 'none') {
                    detail.style.display = '';
                    if (icon) { icon.classList.remove('fa-chevron-down'); icon.classList.add('fa-chevron-up'); }
                } else {
                    detail.style.display = 'none';
                    if (icon) { icon.classList.remove('fa-chevron-up'); icon.classList.add('fa-chevron-down'); }
                }
            });
        });
    }

    function renderTrialBalance(r, el) {
        var html = '<h3 style="margin:0 0 14px;">Trial Balance</h3>' + _dateSub(r) + '<table class="ac-table"><thead><tr><th>Code</th><th>Account</th><th>Category</th><th class="ac-num">Debit</th><th class="ac-num">Credit</th></tr></thead><tbody>';
        (r.rows || []).forEach(function(row){
            html += '<tr><td><code>' + esc(row.code) + '</code></td><td>' + esc(row.name) + '</td><td>' + catBadge(row.category) + '</td>'
                + '<td class="ac-num ac-dr">' + (row.dr > 0 ? fmt(row.dr) : '') + '</td>'
                + '<td class="ac-num ac-cr">' + (row.cr > 0 ? fmt(row.cr) : '') + '</td></tr>';
        });
        html += '</tbody><tfoot><tr><td colspan="3" style="text-align:right;font-weight:700;">Totals</td>'
            + '<td class="ac-num ac-dr">' + fmt(r.totals.dr) + '</td><td class="ac-num ac-cr">' + fmt(r.totals.cr) + '</td></tr></tfoot></table>';
        var diff = Math.abs(r.totals.dr - r.totals.cr);
        if (diff > 0.01) html += '<p style="color:var(--ac-red);margin-top:8px;">Difference: ' + fmt(diff) + ' (out of balance)</p>';
        else html += '<p style="color:var(--ac-green);margin-top:8px;">Balanced</p>';
        el.innerHTML = html;
    }

    function renderProfitLoss(r, el) {
        var html = '<h3 style="margin:0 0 14px;">Profit & Loss Statement</h3>' + _dateSub(r);

        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">';
        // Income
        html += '<div><h4 style="color:var(--ac-green);margin-bottom:8px;">Income</h4><table class="ac-table"><thead><tr><th>Account</th><th class="ac-num">Amount</th></tr></thead><tbody>';
        (r.income || []).forEach(function(row){ html += '<tr><td>' + esc(row.name) + '</td><td class="ac-num">' + fmt(row.amount) + '</td></tr>'; });
        html += '</tbody><tfoot><tr><td style="font-weight:700;">Total Income</td><td class="ac-num" style="font-weight:700;color:var(--ac-green)">' + fmt(r.total_income) + '</td></tr></tfoot></table></div>';

        // Expenses
        html += '<div><h4 style="color:var(--ac-red);margin-bottom:8px;">Expenses</h4><table class="ac-table"><thead><tr><th>Account</th><th class="ac-num">Amount</th></tr></thead><tbody>';
        (r.expenses || []).forEach(function(row){ html += '<tr><td>' + esc(row.name) + '</td><td class="ac-num">' + fmt(row.amount) + '</td></tr>'; });
        html += '</tbody><tfoot><tr><td style="font-weight:700;">Total Expenses</td><td class="ac-num" style="font-weight:700;color:var(--ac-red)">' + fmt(r.total_expense) + '</td></tr></tfoot></table></div>';
        html += '</div>';

        var netColor = r.net_profit >= 0 ? 'var(--ac-green)' : 'var(--ac-red)';
        html += '<div style="margin-top:16px;padding:16px;background:var(--ac-bg3);border-radius:var(--ac-r);text-align:center;">'
            + '<span style="font-size:18px;font-weight:700;color:' + netColor + ';">' + (r.net_profit >= 0 ? 'Net Profit' : 'Net Loss') + ': ' + fmt(Math.abs(r.net_profit)) + '</span></div>';
        el.innerHTML = html;
    }

    function renderBalanceSheet(r, el) {
        var html = '<h3 style="margin:0 0 14px;">Balance Sheet</h3>' + _dateSub(r);
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;">';

        // Assets
        html += '<div><h4 style="margin-bottom:8px;">Assets</h4><table class="ac-table"><thead><tr><th>Account</th><th class="ac-num">Amount</th></tr></thead><tbody>';
        (r.assets || []).forEach(function(row){ html += '<tr><td>' + esc(row.name) + '</td><td class="ac-num">' + fmt(row.amount) + '</td></tr>'; });
        html += '</tbody><tfoot><tr><td style="font-weight:700;">Total Assets</td><td class="ac-num" style="font-weight:700;">' + fmt(r.totals.assets) + '</td></tr></tfoot></table></div>';

        // Liabilities + Equity
        html += '<div><h4 style="margin-bottom:8px;">Liabilities & Equity</h4><table class="ac-table"><thead><tr><th>Account</th><th class="ac-num">Amount</th></tr></thead><tbody>';
        (r.liabilities || []).forEach(function(row){ html += '<tr><td>' + esc(row.name) + '</td><td class="ac-num">' + fmt(row.amount) + '</td></tr>'; });
        (r.equity || []).forEach(function(row){ html += '<tr><td><em>' + esc(row.name) + '</em></td><td class="ac-num">' + fmt(row.amount) + '</td></tr>'; });
        html += '</tbody><tfoot><tr><td style="font-weight:700;">Total Liab. + Equity</td><td class="ac-num" style="font-weight:700;">' + fmt(r.totals.liabilities_equity) + '</td></tr></tfoot></table></div>';
        html += '</div>';

        var diff = Math.abs(r.totals.assets - r.totals.liabilities_equity);
        if (diff > 0.01) html += '<p style="color:var(--ac-red);margin-top:8px;">Difference: ' + fmt(diff) + ' (not balanced)</p>';
        else html += '<p style="color:var(--ac-green);margin-top:8px;">Balanced (Assets = Liabilities + Equity)</p>';
        el.innerHTML = html;
    }

    function renderCashFlow(r, el) {
        var html = '<h3 style="margin:0 0 14px;">Cash Flow Statement</h3>' + _dateSub(r);

        // Entry count + suspect warning
        var metaParts = [];
        if (r.entry_count !== undefined) metaParts.push('<i class="fa fa-database" style="margin-right:3px"></i>' + r.entry_count + ' entries processed');
        if (r.suspect_duplicates) metaParts.push('<span style="color:#d97706"><i class="fa fa-exclamation-triangle" style="margin-right:3px"></i>' + r.suspect_duplicates + ' possible duplicate pair(s) detected — flagged below</span>');
        if (metaParts.length) html += '<div style="font:400 11px/1.4 var(--font-m);color:var(--ac-text3);margin-bottom:10px">' + metaParts.join(' &nbsp;|&nbsp; ') + '</div>';

        // Opening → Net → Closing summary
        var ncColor = r.net_change >= 0 ? 'var(--ac-green)' : 'var(--ac-red)';
        html += '<div class="ac-stats">'
            + '<div class="ac-stat"><div class="ac-stat-label">Opening Balance</div><div class="ac-stat-value">' + fmt(r.opening_balance) + '</div></div>'
            + '<div class="ac-stat"><div class="ac-stat-label">Operating</div><div class="ac-stat-value" style="color:' + (r.operating_total >= 0 ? 'var(--ac-green)' : 'var(--ac-red)') + '">' + fmt(r.operating_total) + '</div></div>'
            + '<div class="ac-stat"><div class="ac-stat-label">Investing</div><div class="ac-stat-value" style="color:' + (r.investing_total >= 0 ? 'var(--ac-green)' : 'var(--ac-red)') + '">' + fmt(r.investing_total) + '</div></div>'
            + '<div class="ac-stat"><div class="ac-stat-label">Financing</div><div class="ac-stat-value" style="color:' + (r.financing_total >= 0 ? 'var(--ac-green)' : 'var(--ac-red)') + '">' + fmt(r.financing_total) + '</div></div>'
            + '<div class="ac-stat"><div class="ac-stat-label">Net Cash Change</div><div class="ac-stat-value" style="color:' + ncColor + '">' + fmt(r.net_change) + '</div></div>'
            + '<div class="ac-stat" style="border:2px solid var(--ac-primary)"><div class="ac-stat-label">Closing Balance</div><div class="ac-stat-value" style="color:var(--ac-primary);font-size:22px">' + fmt(r.closing_balance) + '</div></div>'
            + '</div>';

        // Verification line
        html += '<div style="text-align:center;font:400 12px/1.5 var(--font-m);color:var(--ac-text3);margin:8px 0 16px">'
            + 'Opening (' + fmt(r.opening_balance) + ') + Net Change (' + fmt(r.net_change) + ') = Closing (' + fmt(r.closing_balance) + ')</div>';

        // Itemized sections
        function _srcBadge(src, isSystem) {
            var labels = {income:'Receipt',expense:'Payment',HR_Payroll:'Payroll',manual:'Manual',fees:'Fees'};
            var label = labels[src]||src||'Manual';
            var bg = isSystem ? 'rgba(15,118,110,.1)' : 'var(--ac-bg3)';
            var fg = isSystem ? 'var(--ac-primary)' : 'var(--ac-text3)';
            return '<span style="font-size:9px;padding:2px 6px;border-radius:3px;background:'+bg+';color:'+fg+';font-weight:600">' + label + '</span>';
        }

        function _cfSection(title, icon, color, items, total) {
            var s = '<div style="margin-bottom:18px">';
            s += '<div style="font:700 14px/1.3 var(--font-b);color:' + color + ';margin-bottom:8px;display:flex;align-items:center;gap:6px">'
                + '<i class="fa ' + icon + '"></i> ' + title
                + ' <span style="margin-left:auto;font-family:var(--font-m)">' + fmt(total) + '</span></div>';
            if (!items || !items.length) {
                s += '<div style="padding:12px;font:400 12px/1.5 var(--font-m);color:var(--ac-text3);background:var(--ac-bg3);border-radius:6px">No ' + title.toLowerCase() + ' transactions.</div>';
            } else {
                s += '<table class="ac-table"><thead><tr><th>Date</th><th>Voucher</th><th>Source</th><th>Description</th><th class="ac-num">Inflow</th><th class="ac-num">Outflow</th><th class="ac-num">Net</th></tr></thead><tbody>';
                items.forEach(function(t) {
                    var netC = t.net >= 0 ? 'var(--ac-green)' : 'var(--ac-red)';
                    var dupStyle = t.possible_duplicate ? 'background:rgba(217,119,6,.06);' : '';
                    var dupTag = t.possible_duplicate ? ' <i class="fa fa-exclamation-triangle" style="color:#d97706;font-size:10px" title="Possible duplicate — same date, amount, and similar narration as another entry"></i>' : '';
                    s += '<tr style="' + dupStyle + '"><td>' + esc(t.date) + '</td>'
                        + '<td><code style="font-size:11px">' + esc(t.voucher_no) + '</code></td>'
                        + '<td>' + _srcBadge(t.source, t.is_system) + '</td>'
                        + '<td>' + esc(t.narration) + dupTag + '</td>'
                        + '<td class="ac-num ac-dr">' + (t.inflow > 0 ? fmt(t.inflow) : '') + '</td>'
                        + '<td class="ac-num ac-cr">' + (t.outflow > 0 ? fmt(t.outflow) : '') + '</td>'
                        + '<td class="ac-num" style="font-weight:600;color:' + netC + '">' + fmt(t.net) + '</td></tr>';
                });
                s += '</tbody><tfoot><tr><td colspan="6" style="text-align:right;font-weight:700">Subtotal</td><td class="ac-num" style="font-weight:700;color:' + color + '">' + fmt(total) + '</td></tr></tfoot></table>';
            }
            s += '</div>';
            return s;
        }

        html += _cfSection('Operating Activities', 'fa-cogs', 'var(--ac-primary)', r.operating, r.operating_total);
        html += _cfSection('Investing Activities', 'fa-building', 'var(--ac-blue)', r.investing, r.investing_total);
        html += _cfSection('Financing Activities', 'fa-university', 'var(--ac-amber)', r.financing, r.financing_total);

        el.innerHTML = html;
    }

    // ══════════════════════════════════════════════
    //  SETTINGS
    // ══════════════════════════════════════════════
    function loadSettings() {
        getJSON('accounting/get_settings').then(function(r){
            if (r.status !== 'success') return;
            document.getElementById('settLockCurrent').value = (r.period_lock && r.period_lock.locked_until) || 'None';
            var countersHtml = '';
            var counters = r.counters || {};
            Object.keys(counters).forEach(function(k){ countersHtml += '<p><strong>' + esc(k) + ':</strong> ' + counters[k] + '</p>'; });
            document.getElementById('settCounters').innerHTML = countersHtml || '<p>No counters yet.</p>';
        });

        getJSON('accounting/get_migration_status').then(function(r){
            if (r.status !== 'success') return;
            document.getElementById('migrationStatus').innerHTML =
                '<p>Chart of Accounts: <strong>' + r.coa_count + '</strong> accounts</p>'
                + '<p>Old Account Book: ' + (r.has_old_book ? '<strong>Yes</strong> (can migrate)' : 'None') + '</p>';
        });
    }

    function lockPeriod() {
        var d = document.getElementById('settLockDate').value;
        if (!d) return toast('Select a date.', 'error');
        if (!confirm('Lock all entries on or before ' + d + '? This cannot be undone.')) return;
        post('accounting/lock_period', { locked_until: d }).then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');
            toast(r.message); loadSettings();
        });
    }

    function migrateAccounts() {
        if (!confirm('Migrate existing Account Book entries to Chart of Accounts?')) return;
        post('accounting/migrate_existing_accounts').then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');
            toast(r.message); loadCoA(); loadSettings();
        });
    }

    function recomputeBalances() {
        post('accounting/recompute_balances').then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');
            toast(r.message);
        });
    }

    function carryForward() {
        if (!confirm('Carry forward closing balances as next year opening balances? This updates the Chart of Accounts.')) return;
        post('accounting/carry_forward_balances').then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');
            toast(r.message); loadCoA();
        });
    }

    // ══════════════════════════════════════════════
    //  AUDIT LOG
    // ══════════════════════════════════════════════
    function loadAuditLog() {
        getJSON('accounting/get_audit_log?limit=50').then(function(r){
            if (r.status !== 'success') return toast(r.message, 'error');
            var body = document.getElementById('auditBody');
            if (!(r.logs || []).length) { body.innerHTML = '<tr><td colspan="5" class="ac-empty">No audit entries yet.</td></tr>'; return; }
            var html = '';
            r.logs.forEach(function(log){
                var ts = log.timestamp ? new Date(log.timestamp).toLocaleString('en-IN') : '';
                html += '<tr>'
                    + '<td style="font-size:12px;white-space:nowrap;">' + esc(ts) + '</td>'
                    + '<td>' + esc(log.admin_name || log.admin_id) + '</td>'
                    + '<td><span class="ac-badge" style="background:var(--ac-bg3);color:var(--ac-text);">' + esc(log.action) + '</span></td>'
                    + '<td>' + esc(log.entity_type) + ' <code>' + esc(log.entity_id || '') + '</code></td>'
                    + '<td style="font-size:12px;max-width:200px;overflow:hidden;text-overflow:ellipsis;">' + esc(JSON.stringify(log.new_value || log.old_value || '').substring(0, 80)) + '</td>'
                    + '</tr>';
            });
            body.innerHTML = html;
        });
    }

    // ── Public API ──
    window.AC = {
        loadCoA: loadCoA, showAccountModal: showAccountModal, saveAccount: saveAccount,
        editAccount: editAccount, deleteAccount: deleteAccount, seedChart: seedChart,
        loadLedger: loadLedger, loadMoreLedger: loadMoreLedger,
        showJournalModal: showJournalModal, addJELine: addJELine,
        updateJETotals: updateJETotals, saveJournalEntry: saveJournalEntry,
        deleteJE: deleteJE, finalizeJE: finalizeJE,
        loadIE: loadIE, loadMoreIE: loadMoreIE,
        showIEModal: showIEModal, saveIE: saveIE, deleteIE: deleteIE,
        loadCashBook: loadCashBook, populateCashBookAccounts: populateCashBookAccounts,
        loadBankAccounts: loadBankAccounts, loadBankRecon: loadBankRecon,
        showImportCSV: showImportCSV, matchPrompt: matchPrompt, doMatch: doMatch,
        unmatchTxn: unmatchTxn,
        generateReport: generateReport, exportReport: exportReport, loadSettings: loadSettings,
        lockPeriod: lockPeriod, migrateAccounts: migrateAccounts,
        recomputeBalances: recomputeBalances, carryForward: carryForward,
        loadAuditLog: loadAuditLog, closeModal: closeModal,
    };

    // ── Apply role visibility & auto-load data for active tab ──
    applyRoleVisibility();
    var activeTab = '<?= $at ?>';
    loadCoA(); // always load CoA (needed for account dropdowns)
    if (activeTab === 'ledger') { loadLedger(); }
    else if (activeTab === 'income-expense') { loadIE(); }
    else if (activeTab === 'settings') { loadSettings(); loadAuditLog(); }
    else if (activeTab === 'bank-recon') { loadBankAccounts(); }
    else if (activeTab === 'cash-book') { populateCashBookAccounts(); }
})();
</script>
