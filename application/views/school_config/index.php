<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>

<style>
/* ── School Config Page ───────────────────────────────────── */
.sc-wrap { padding: 20px 22px 40px; }

.sc-head {
    display:flex; align-items:center; gap:14px;
    padding: 18px 22px; margin-bottom: 22px;
    background: var(--bg2); border: 1px solid var(--border);
    border-radius: var(--r); box-shadow: var(--sh);
}
.sc-head-icon {
    width:44px; height:44px; border-radius:10px;
    background:var(--gold); display:flex; align-items:center;
    justify-content:center; flex-shrink:0;
    box-shadow:0 0 18px var(--gold-glow);
}
.sc-head-icon i { color:#fff; font-size:20px; }
.sc-head-title { font-size:18px; font-weight:700; color:var(--t1); font-family:var(--font-d); }
.sc-head-sub   { font-size:12px; color:var(--t3); margin-top:2px; }

/* ── Nav Tabs ─────────────────────────────────────────────── */
.sc-tabs { display:flex; gap:4px; flex-wrap:wrap; margin-bottom:20px; }
.sc-tab {
    display:flex; align-items:center; gap:7px;
    padding:8px 16px; border-radius:8px; border:1px solid var(--border);
    background:var(--bg2); color:var(--t2); font-size:12.5px; font-weight:600;
    cursor:pointer; transition:all var(--ease); white-space:nowrap;
}
.sc-tab:hover { border-color:var(--gold); color:var(--gold); }
.sc-tab.active {
    background:var(--gold); color:#fff; border-color:var(--gold);
    box-shadow:0 0 14px var(--gold-glow);
}
.sc-tab i { font-size:13px; }

/* ── Step Hint Banner ── */
.sc-step-hint {
    display:flex; align-items:center; gap:10px;
    padding:10px 16px; margin-bottom:14px;
    background:var(--gold-dim); border-left:4px solid var(--gold);
    border-radius:0 8px 8px 0; font-size:12.5px; color:var(--t2); line-height:1.5;
}
.sc-step-badge {
    display:inline-flex; align-items:center; justify-content:center;
    padding:3px 10px; border-radius:12px; font-size:11px; font-weight:700;
    background:var(--gold); color:#fff; white-space:nowrap; flex-shrink:0;
}

/* ── Tab Panes ────────────────────────────────────────────── */
.sc-pane { display:none; }
.sc-pane.active { display:block; }

/* ── Card ─────────────────────────────────────────────────── */
.sc-card {
    background:var(--bg2); border:1px solid var(--border);
    border-radius:var(--r); padding:22px; margin-bottom:18px;
    box-shadow:var(--sh);
}
.sc-card-title {
    font-size:14px; font-weight:700; color:var(--t1);
    margin-bottom:16px; display:flex; align-items:center; gap:8px;
}
.sc-card-title i { color:var(--gold); font-size:15px; }

/* ── Form Grid ────────────────────────────────────────────── */
.sc-grid { display:grid; gap:14px; }
.sc-grid-1 { grid-template-columns:1fr; }
.sc-grid-2 { grid-template-columns:1fr 1fr; }
.sc-grid-3 { grid-template-columns:1fr 1fr 1fr; }
@media(max-width:640px){ .sc-grid-2,.sc-grid-3{ grid-template-columns:1fr; } }

.sc-field label {
    display:block; font-size:11.5px; font-weight:600;
    color:var(--t2); margin-bottom:5px; text-transform:uppercase; letter-spacing:.4px;
}
.sc-field input, .sc-field select, .sc-field textarea {
    width:100%; padding:9px 12px; border-radius:8px;
    border:1px solid var(--border); background:var(--bg3);
    color:var(--t1); font-size:13px; font-family:var(--font-b);
    transition:border-color var(--ease), box-shadow var(--ease);
    outline:none;
}
.sc-field input:focus, .sc-field select:focus, .sc-field textarea:focus {
    border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring);
}
.sc-field select option { background:var(--bg3); }

/* ── Buttons ──────────────────────────────────────────────── */
.sc-btn {
    display:inline-flex; align-items:center; gap:7px;
    padding:9px 18px; border-radius:8px; border:none;
    font-size:13px; font-weight:600; cursor:pointer;
    transition:all var(--ease); font-family:var(--font-b);
}
.sc-btn-primary {
    background:var(--gold); color:#fff;
    box-shadow:0 2px 10px var(--gold-ring);
}
.sc-btn-primary:hover { background:var(--gold2); }
.sc-btn-ghost {
    background:transparent; color:var(--t2);
    border:1px solid var(--border);
}
.sc-btn-ghost:hover { border-color:var(--gold); color:var(--gold); }
.sc-btn-danger { background:#c0392b; color:#fff; }
.sc-btn-danger:hover { background:#a93226; }
.sc-btn-warning { background:#d97706; color:#fff; }
.sc-btn-warning:hover { background:#b45309; }
.sc-btn-sm { padding:5px 12px; font-size:11.5px; }

/* ── Radio Group ──────────────────────────────────────────── */
.sc-radio-group { display:flex; gap:10px; flex-wrap:wrap; }
.sc-radio-opt {
    display:flex; align-items:center; gap:7px;
    padding:8px 14px; border-radius:8px; border:1px solid var(--border);
    background:var(--bg3); cursor:pointer; transition:all var(--ease);
    font-size:13px; color:var(--t2); font-weight:600;
}
.sc-radio-opt.sc-checked {
    border-color:var(--gold); background:var(--gold-dim); color:var(--gold);
}
.sc-radio-opt input { display:none; }

/* ── Table ────────────────────────────────────────────────── */
.sc-table { width:100%; border-collapse:collapse; }
.sc-table th {
    text-align:left; padding:8px 12px; font-size:11px;
    font-weight:700; color:var(--t3); text-transform:uppercase;
    letter-spacing:.5px; border-bottom:1px solid var(--border);
}
.sc-table td {
    padding:9px 12px; font-size:13px; color:var(--t1);
    border-bottom:1px solid var(--border);
}
.sc-table tr:last-child td { border-bottom:none; }
.sc-table tr:hover td { background:var(--gold-dim); }

/* ── Tag / Badge ──────────────────────────────────────────── */
.sc-tag {
    display:inline-block; padding:2px 9px; border-radius:20px;
    font-size:10.5px; font-weight:700; font-family:var(--font-m);
}
.sc-tag-teal   { background:var(--gold-dim); color:var(--gold); border:1px solid var(--gold-ring); }
.sc-tag-amber  { background:rgba(217,119,6,.12); color:#d97706; border:1px solid rgba(217,119,6,.25); }
.sc-tag-green  { background:rgba(21,128,61,.12); color:#15803d; border:1px solid rgba(21,128,61,.25); }
.sc-tag-red    { background:rgba(192,57,43,.12); color:#c0392b; border:1px solid rgba(192,57,43,.25); }
.sc-tag-gray   { background:var(--bg3); color:var(--t3); border:1px solid var(--border); }

/* ── Session List ─────────────────────────────────────────── */
.sc-sess-list { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
.sc-sess-pill {
    display:flex; align-items:center; gap:8px;
    padding:8px 14px; border-radius:24px; border:1px solid var(--border);
    background:var(--bg3); font-size:13px; font-weight:600;
    color:var(--t2); transition:all var(--ease);
}
.sc-sess-pill.archived-sess { opacity:0.55; border-style:dashed; }
.sc-sess-pill.archived-sess .sc-sess-badge { background:#6b7280; }
.sc-sess-pill .sc-sess-action {
    font-size:10.5px;
    padding:2px 8px;
    border-radius:10px;
    cursor:pointer;
    background:transparent;
    border:1px solid var(--border);
    color:var(--t2);
    margin-left:4px;
}
.sc-sess-pill .sc-sess-action:hover { background:var(--bg3); }
.sc-sess-pill .sc-sess-action.danger { color:#dc2626; border-color:rgba(220,38,38,.35); }
.sc-sess-pill .sc-sess-action.danger:hover { background:rgba(220,38,38,.08); }
.sc-sess-pill.active-sess {
    border-color:var(--gold); background:var(--gold-dim); color:var(--gold);
}
.sc-sess-pill .sc-sess-badge {
    font-size:9px; font-weight:800; font-family:var(--font-m);
    background:var(--gold); color:#fff; padding:1px 6px;
    border-radius:10px;
}
.sc-sess-pill .sc-sess-set {
    font-size:10.5px; color:var(--t3); cursor:pointer;
    text-decoration:underline;
}
.sc-sess-pill .sc-sess-set:hover { color:var(--gold); }

/* ── Grade Scale Table ────────────────────────────────────── */
.sc-gs-row { display:grid; grid-template-columns:1fr 1fr 1fr auto; gap:8px; align-items:center; margin-bottom:8px; }
.sc-gs-row input { padding:7px 10px; }

/* ── Classes List ─────────────────────────────────────────── */
.sc-class-list { display:flex; flex-direction:column; gap:6px; }
.sc-class-row {
    display:grid; grid-template-columns:auto 1fr auto auto auto auto;
    gap:10px; align-items:center; padding:8px 12px;
    background:var(--bg3); border-radius:8px; border:1px solid var(--border);
}
.sc-class-row.deleted-row {
    opacity:0.5; border-style:dashed;
}
.sc-class-row input[type=text] {
    padding:6px 10px; font-size:13px; font-family:var(--font-b);
    background:var(--bg2); color:var(--t1);
    border:1px solid var(--border); border-radius:6px; outline:none;
}
.sc-class-row input[type=text]:focus {
    border-color:var(--gold); box-shadow:0 0 0 2px var(--gold-ring);
}

/* ── Logo Preview ─────────────────────────────────────────── */
.sc-logo-wrap { display:flex; align-items:center; gap:16px; }
.sc-logo-img {
    width:80px; height:80px; border-radius:12px;
    object-fit:contain; border:2px solid var(--border); background:var(--bg3);
}
.sc-logo-placeholder {
    width:80px; height:80px; border-radius:12px;
    border:2px dashed var(--border); background:var(--bg3);
    display:flex; align-items:center; justify-content:center;
    color:var(--t3); font-size:24px;
}

/* ── Empty state ──────────────────────────────────────────── */
.sc-empty {
    text-align:center; padding:30px 20px; color:var(--t3);
    font-size:13px;
}
.sc-empty i { font-size:36px; display:block; margin-bottom:8px; }

/* ── Toast ────────────────────────────────────────────────── */
#scToast {
    position:fixed; bottom:26px; right:26px; z-index:9999;
    min-width:240px; max-width:360px; padding:12px 18px;
    border-radius:10px; font-size:13px; font-weight:600;
    display:flex; align-items:center; gap:10px;
    box-shadow:0 8px 32px rgba(0,0,0,.3);
    transform:translateY(80px); opacity:0;
    transition:transform .3s cubic-bezier(.4,0,.2,1), opacity .3s;
    pointer-events:none;
}
#scToast.show { transform:translateY(0); opacity:1; pointer-events:auto; }
#scToast.ok   { background:var(--gold); color:#fff; }
#scToast.err  { background:#c0392b; color:#fff; }

/* ── Loading spinner ──────────────────────────────────────── */
.sc-spin { display:inline-block; animation:spin .7s linear infinite; }
@keyframes spin { to { transform:rotate(360deg); } }

/* ── Report Card Template Selector ───────────────────────── */
.rct-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(170px, 1fr)); gap:14px; }
.rct-card {
    border:2px solid var(--border); border-radius:10px; padding:12px;
    cursor:pointer; transition:all .2s var(--ease); background:var(--bg2);
    text-align:center;
}
.rct-card:hover { border-color:var(--gold); transform:translateY(-2px); box-shadow:0 4px 12px var(--gold-glow); }
.rct-card.active { border-color:var(--gold); background:var(--gold-dim); box-shadow:0 0 0 3px var(--gold-ring); }
.rct-preview {
    width:100%; height:90px; border:1px solid var(--border); border-radius:6px;
    margin-bottom:10px; overflow:hidden; background:#fff; display:flex;
    flex-direction:column; justify-content:space-between;
}
.rct-mini-header { height:12px; }
.rct-mini-row { height:10px; margin:2px 6px; background:#eee; border-radius:2px; border:1px solid #ddd; }
.rct-mini-footer { height:8px; }
.rct-name { font-size:13px; font-weight:700; color:var(--t1); margin-bottom:4px; }
.rct-desc { font-size:11px; color:var(--t3); line-height:1.4; }

/* ── Report Card Customization Panel ──────────────────────── */
.rcc-layout { display:grid; grid-template-columns:1fr 420px; gap:24px; align-items:start; }
@media (max-width:1100px){ .rcc-layout { grid-template-columns:1fr; } }
.rcc-group { margin-bottom:20px; }
.rcc-group-title { font-size:12px; font-weight:700; color:var(--t1); text-transform:uppercase; letter-spacing:.6px; margin-bottom:10px; padding-bottom:6px; border-bottom:1px solid var(--border); }
.rcc-color-row { display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.rcc-color-row input[type=color]{ width:42px; height:38px; padding:0; border:1px solid var(--border); border-radius:8px; background:none; cursor:pointer; }
.rcc-hex { width:96px; padding:8px 10px; border:1px solid var(--border); border-radius:8px; background:var(--bg2); color:var(--t1); font-family:monospace; font-size:13px; text-transform:uppercase; }
.rcc-swatches { display:flex; gap:6px; flex-wrap:wrap; }
.rcc-sw { width:26px; height:26px; border-radius:50%; border:2px solid var(--border); cursor:pointer; transition:transform .15s var(--ease); padding:0; }
.rcc-sw:hover { transform:scale(1.12); }
.rcc-sw.active { box-shadow:0 0 0 3px var(--gold-ring); border-color:#fff; }
.rcc-toggles { display:grid; grid-template-columns:1fr 1fr; gap:10px 16px; }
.rcc-toggle { display:flex; align-items:center; gap:8px; font-size:13px; color:var(--t2); cursor:pointer; }
.rcc-toggle input { width:16px; height:16px; accent-color:var(--gold); cursor:pointer; }
.rcc-fields { display:grid; grid-template-columns:1fr 1fr; gap:12px 16px; }
.rcc-field { display:flex; flex-direction:column; gap:4px; }
.rcc-field-wide { grid-column:1 / -1; }
.rcc-field label { font-size:11px; font-weight:600; color:var(--t3); }
.rcc-field input { padding:8px 10px; border:1px solid var(--border); border-radius:8px; background:var(--bg2); color:var(--t1); font-size:13px; }
.rcc-field input:focus { border-color:var(--gold); outline:none; }
.rcc-assets { display:flex; gap:14px; flex-wrap:wrap; }
.rcc-asset { width:130px; text-align:center; }
.rcc-asset-prev { width:130px; height:74px; border:1px dashed var(--border); border-radius:8px; background:#fff; display:flex; align-items:center; justify-content:center; overflow:hidden; }
.rcc-asset-prev img { max-width:100%; max-height:100%; object-fit:contain; }
.rcc-asset-ph { font-size:11px; color:#9aa3af; }
.rcc-asset-label { font-size:11px; font-weight:600; color:var(--t2); margin:6px 0 5px; }
.rcc-asset-btns { display:flex; gap:6px; justify-content:center; flex-wrap:wrap; }
.rcc-file { display:none !important; }   /* theme overrides the [hidden] attr — force hide the native input */
.rcc-mini-btn { display:inline-block; }
.rcc-mini-btn { font-size:11px; padding:4px 10px; border-radius:6px; border:1px solid var(--border); background:var(--bg2); color:var(--t2); cursor:pointer; }
.rcc-mini-btn:hover { border-color:var(--gold); color:var(--gold); }
.rcc-mini-rm { color:#c0392b; }
.rcc-mini-rm:hover { border-color:#c0392b; color:#c0392b; }
.rcc-asset-note { font-size:11px; color:var(--t3); margin-top:10px; }
.rcc-preview-col { position:sticky; top:14px; border:1px solid var(--border); border-radius:10px; overflow:hidden; background:#e9edf2; }
.rcc-preview-bar { background:var(--bg2); border-bottom:1px solid var(--border); padding:8px 12px; font-size:12px; font-weight:600; color:var(--t2); display:flex; align-items:center; gap:6px; }
.rcc-preview-frame { padding:14px; max-height:640px; overflow:auto; }

/* Mini report-card preview (self-contained, light-themed regardless of admin theme) */
.rcp { background:#fff; border:1px solid #d8dee6; border-top:4px solid var(--rcp-accent,#1a3a6b); border-radius:4px; font-family:'Segoe UI',Arial,sans-serif; color:#1f2733; box-shadow:0 4px 16px rgba(15,23,42,.12); }
.rcp-head { display:flex; align-items:center; gap:10px; padding:12px 14px 8px; }
.rcp-logo { width:42px; height:42px; border-radius:6px; object-fit:contain; background:var(--rcp-tint,#eef2f7); display:flex; align-items:center; justify-content:center; color:var(--rcp-accent,#1a3a6b); font-weight:800; font-size:15px; flex-shrink:0; }
.rcp-logo img { width:100%; height:100%; object-fit:contain; border-radius:6px; }
.rcp-head-c { flex:1; text-align:center; }
.rcp-school { font-size:15px; font-weight:800; color:var(--rcp-dark,#13294b); line-height:1.1; }
.rcp-sub { font-size:8px; color:#94a3b8; margin-top:2px; }
.rcp-title { background:var(--rcp-accent,#1a3a6b); color:#fff; text-align:center; font-size:9px; font-weight:700; letter-spacing:1.5px; text-transform:uppercase; padding:4px; }
.rcp-stu { display:flex; gap:8px; padding:9px 14px; border-bottom:1px solid #eef1f5; font-size:9px; }
.rcp-stu-grid { flex:1; display:grid; grid-template-columns:1fr 1fr; gap:3px 12px; }
.rcp-stu-grid div span { color:#94a3b8; }
.rcp-photo { width:42px; height:50px; border:1px solid #d8dee6; border-radius:4px; background:#f8fafc; display:flex; align-items:center; justify-content:center; font-size:7px; color:#b8c0cc; flex-shrink:0; }
.rcp-seclbl { background:var(--rcp-tint,#eef2f7); color:var(--rcp-dark,#13294b); font-size:8px; font-weight:700; letter-spacing:.8px; text-transform:uppercase; padding:4px 14px; border-left:3px solid var(--rcp-accent,#1a3a6b); }
.rcp-tbl { width:100%; border-collapse:collapse; font-size:8.5px; }
.rcp-tbl th { background:var(--rcp-accent,#1a3a6b); color:#fff; padding:4px 5px; font-weight:600; text-align:center; }
.rcp-tbl td { padding:4px 5px; border:1px solid #e2e8f0; text-align:center; }
.rcp-tbl td.l { text-align:left; font-weight:600; }
.rcp-tbl tr:nth-child(even) td { background:#f8fafc; }
.rcp-strip { display:flex; border-bottom:1px solid #eef1f5; }
.rcp-strip div { flex:1; text-align:center; padding:7px 2px; border-right:1px solid #eef1f5; }
.rcp-strip div:last-child { border-right:none; }
.rcp-strip b { display:block; font-size:13px; color:var(--rcp-dark,#13294b); }
.rcp-strip small { font-size:7px; color:#94a3b8; text-transform:uppercase; letter-spacing:.3px; }
.rcp-legend { font-size:7px; color:#475569; padding:4px 14px; background:#fffbeb; border-bottom:1px solid #fde68a; font-style:italic; }
.rcp-cosch { display:grid; grid-template-columns:1fr 1fr; }
.rcp-cosch div { display:flex; justify-content:space-between; padding:5px 14px; border-bottom:1px solid #eef1f5; border-right:1px solid #eef1f5; font-size:8.5px; }
.rcp-cosch div:nth-child(2n){ border-right:none; }
.rcp-att { display:flex; border-bottom:1px solid #eef1f5; }
.rcp-att div { flex:1; padding:5px 14px; border-right:1px solid #eef1f5; font-size:8px; }
.rcp-att div:last-child{ border-right:none; }
.rcp-att span { display:block; color:#94a3b8; font-size:7px; text-transform:uppercase; }
.rcp-sigs { display:flex; padding:14px 0 12px; }
.rcp-sig { flex:1; text-align:center; }
.rcp-sig img { height:22px; max-width:70px; object-fit:contain; }
.rcp-sig .ln { width:70%; margin:6px auto 4px; border-top:1px solid #94a3b8; }
.rcp-sig .nm { font-size:8px; font-weight:700; color:var(--rcp-dark,#13294b); }
.rcp-foot { text-align:center; font-size:7px; color:#94a3b8; font-style:italic; padding:6px; border-top:1px solid #eef1f5; }
</style>

<div class="content-wrapper">
<div class="sc-wrap">

    <!-- Page Header -->
    <div class="sc-head">
        <div class="sc-head-icon"><i class="fa fa-university"></i></div>
        <div>
            <div class="sc-head-title">Academic Setup</div>
            <div class="sc-head-sub">Set up your school step by step: Profile &rarr; Board &rarr; Sessions &rarr; Classes &rarr; Streams &rarr; Sections &rarr; Subjects &rarr; Report Card</div>
        </div>
    </div>

    <!-- Tab Navigation — ordered by natural school setup sequence -->
    <div class="sc-tabs" id="scTabs">
        <button class="sc-tab active" data-tab="profile">
            <i class="fa fa-building-o"></i>Profile
        </button>
        <button class="sc-tab" data-tab="board">
            <i class="fa fa-graduation-cap"></i>Board
        </button>
        <button class="sc-tab" data-tab="sessions">
            <i class="fa fa-calendar"></i>Sessions
        </button>
        <button class="sc-tab" data-tab="classes">
            <i class="fa fa-th-large"></i>Classes
        </button>
        <button class="sc-tab" data-tab="streams">
            <i class="fa fa-code-fork"></i>Streams
        </button>
        <button class="sc-tab" data-tab="sections">
            <i class="fa fa-columns"></i>Sections
        </button>
        <button class="sc-tab" data-tab="subjects">
            <i class="fa fa-book"></i> Subjects
        </button>
        <button class="sc-tab" data-tab="reportcard">
            <i class="fa fa-file-text-o"></i> Report Card
        </button>
        <!-- Phase 1 ZenX HRMS — hidden by default; shown only when the
             tenant has HRMS_CORE_CAPABILITY (see renderOrganisation()).
             Not enabled for a tenant = this tab is invisible, i.e. the page
             behaves exactly as it did before this feature existed. -->
        <button class="sc-tab" data-tab="onboarding">
            <i class="fa fa-list-ul"></i> Onboarding
        </button>        <button class="sc-tab" data-tab="notifications">
            <i class="fa fa-bell-o"></i> Notifications
        </button>        <button class="sc-tab" data-tab="organisation" id="scTabOrganisation" style="display:none;">
            <i class="fa fa-sitemap"></i> Organisation
        </button>
    </div>

    <!-- ════════════════════════════════════════════════════════ -->
    <!-- TAB: Onboarding checklist                               -->
    <!-- The reader (Staff::_onboarding_tasks) and the whole      -->
    <!-- checklist already existed; nothing wrote this list, so   -->
    <!-- it was configurable only with database access.           -->
    <!-- ════════════════════════════════════════════════════════ -->
    <div class="sc-pane" id="tab-onboarding">
        <div class="sc-step-hint">
            The tasks HR works through when a new employee joins. Leave this empty to use
            the built-in checklist. Changes apply to checklists started from now on —
            anyone already being onboarded keeps the tasks their checklist was opened with.
        </div>

        <div id="onbLoading" style="padding:24px;text-align:center;color:var(--t3);">
            <i class="fa fa-spinner fa-spin"></i> Loading checklist&hellip;
        </div>

        <div id="onbError" style="display:none;padding:16px;border-radius:8px;
             background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.3);color:#dc2626;">
            <i class="fa fa-exclamation-triangle"></i> <span id="onbErrorText"></span>
        </div>

        <div id="onbEditor" style="display:none;">
            <div id="onbUsingDefault" style="display:none;margin-bottom:12px;font-size:12.5px;color:var(--t3);">
                <i class="fa fa-info-circle"></i> This school is using the built-in checklist.
                Add tasks below to replace it.
            </div>

            <div id="onbRows"></div>

            <button class="sc-btn" id="onbAddRow" style="margin-top:10px;">
                <i class="fa fa-plus"></i> Add task
            </button>

            <div style="margin-top:18px;display:flex;gap:8px;align-items:center;">
                <button class="sc-btn" id="onbSave"><i class="fa fa-check"></i> Save checklist</button>
                <span id="onbStatus" style="font-size:12.5px;color:var(--t3);"></span>
            </div>
        </div>
    </div>
    <!-- ════════════════════════════════════════════════════════ -->
    <!-- TAB: Notifications                                      -->
    <!-- Per-school control over which alerts this school sends.  -->
    <!-- Enforced in Push_enqueue_service, the single choke point -->
    <!-- every notification producer passes through.              -->
    <!-- ════════════════════════════════════════════════════════ -->
    <div class="sc-pane" id="tab-notifications">
        <div class="sc-step-hint">
            Choose which notifications this school sends. Switching one off stops it for
            <strong>everyone</strong> in this school — parents and staff alike — and takes
            effect immediately. Anything left on behaves exactly as before.
        </div>

        <div id="notifLoading" style="padding:24px;text-align:center;color:var(--t3);">
            <i class="fa fa-spinner fa-spin"></i> Loading notification settings&hellip;
        </div>

        <div id="notifError" style="display:none;padding:16px;border-radius:8px;
             background:rgba(220,38,38,.08);border:1px solid rgba(220,38,38,.3);color:#dc2626;">
            <i class="fa fa-exclamation-triangle"></i>
            <span id="notifErrorText"></span>
        </div>

        <div id="notifGroups" style="display:none;"></div>
    </div>
    <!-- ════════════════════════════════════════════════════════ -->
    <!-- TAB: Profile                                            -->
    <!-- ════════════════════════════════════════════════════════ -->
    <div class="sc-pane active" id="tab-profile">
        <div class="sc-step-hint">
            <span class="sc-step-badge">Step 1</span>
            Start by setting up your school's identity &mdash; name, address, contact details, and logo.
        </div>
        <div class="sc-card">
            <div class="sc-card-title"><i class="fa fa-image"></i> School Logo</div>
            <div class="sc-logo-wrap">
                <div id="logoPreviewWrap">
                    <div class="sc-logo-placeholder" id="logoPlaceholder"><i class="fa fa-picture-o"></i></div>
                    <img id="logoImg" class="sc-logo-img" style="display:none;" alt="School Logo">
                </div>
                <div>
                    <input type="file" id="logoFile" accept="image/*" style="display:none;">
                    <button class="sc-btn sc-btn-ghost sc-btn-sm" onclick="document.getElementById('logoFile').click()">
                        <i class="fa fa-upload"></i> Upload Logo
                    </button>
                    <div style="font-size:11px;color:var(--t3);margin-top:5px;">JPG, PNG, GIF, WebP · Max 2 MB</div>
                    <div id="logoMsg" style="font-size:11.5px;margin-top:4px;"></div>
                </div>
            </div>
        </div>

        <div class="sc-card">
            <div class="sc-card-title"><i class="fa fa-info-circle"></i> Basic Information</div>
            <div class="sc-grid sc-grid-2">
                <div class="sc-field">
                    <label>School Display Name</label>
                    <input type="text" id="pf_display_name" maxlength="120" placeholder="Full school name for display">
                </div>
                <div class="sc-field">
                    <label>Principal / Head</label>
                    <input type="text" id="pf_principal_name" maxlength="80" placeholder="Principal name">
                </div>
                <div class="sc-field">
                    <label>Established Year</label>
                    <input type="number" id="pf_established_year" min="1800" max="<?= date('Y') ?>" placeholder="e.g. 1995">
                </div>
                <div class="sc-field">
                    <label>Affiliation Board</label>
                    <input type="text" id="pf_affiliation_board" maxlength="80" placeholder="e.g. CBSE">
                </div>
                <div class="sc-field">
                    <label>Affiliation / DISE No.</label>
                    <input type="text" id="pf_affiliation_no" maxlength="60" placeholder="Affiliation or registration number">
                </div>
            </div>
        </div>

        <div class="sc-card">
            <div class="sc-card-title"><i class="fa fa-map-marker"></i> Address &amp; Contact</div>
            <div class="sc-grid sc-grid-1" style="margin-bottom:14px;">
                <div class="sc-field">
                    <label>Street Address</label>
                    <input type="text" id="pf_address" maxlength="200" placeholder="Street / area / locality">
                </div>
            </div>
            <div class="sc-grid sc-grid-3">
                <div class="sc-field">
                    <label>State</label>
                    <select id="pf_state" data-india-state data-india-fill data-india-placeholder="Select State">
                        <option value="">Select State</option>
                    </select>
                </div>
                <div class="sc-field">
                    <label>District</label>
                    <select id="pf_city" data-india-district="pf_state" data-india-placeholder="Select District">
                        <option value="">Select District</option>
                    </select>
                </div>
                <div class="sc-field">
                    <label>Pincode</label>
                    <input type="text" id="pf_pincode" maxlength="10" placeholder="Pincode">
                </div>
                <div class="sc-field">
                    <label>Phone</label>
                    <div class="phone-ig"><span class="phone-pfx">+91</span><input type="tel" id="pf_phone" maxlength="10" placeholder="00000 00000"></div>
                </div>
                <div class="sc-field">
                    <label>Email</label>
                    <input type="email" id="pf_email" maxlength="100" placeholder="contact@school.edu">
                </div>
                <div class="sc-field">
                    <label>Website</label>
                    <input type="text" id="pf_website" maxlength="100" placeholder="https://school.edu">
                </div>
            </div>
        </div>

        <div class="sc-card">
            <div class="sc-card-title"><i class="fa fa-file-text-o"></i> School Documents</div>
            <!-- CANONICAL-2 (2026-09-13): disambiguate these from the FUNCTIONAL calendar.
                 These slots only store a reference PDF/image (a scanned year-planner) and
                 a download link — they do NOT create holidays or working days and do NOT
                 affect student attendance. The calendar that actually drives attendance is
                 Academics → Academic Planner → Academic Calendar (calendarEvents). -->
            <div style="font-size:12px;color:var(--t3);margin:-6px 0 12px;padding:8px 10px;background:rgba(188,90,60,.06);border-left:3px solid var(--gold);border-radius:0 4px 4px 0;">
                <strong>Reference files only.</strong> Uploading a file here does <strong>not</strong> set holidays or working days.
                To set holidays that affect attendance, use <strong>Academics → Academic Calendar</strong>.
            </div>
            <div class="sc-grid sc-grid-2">
                <div class="sc-field">
                    <label>Holidays Calendar <span style="font-weight:400;color:var(--t3);">(reference PDF)</span></label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="file" id="docHolidays" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx" style="display:none;">
                        <button class="sc-btn sc-btn-ghost sc-btn-sm" onclick="document.getElementById('docHolidays').click()">
                            <i class="fa fa-upload"></i> Upload
                        </button>
                        <a id="docHolidaysLink" href="#" target="_blank" style="display:none;font-size:.84rem;color:var(--gold);">
                            <i class="fa fa-download"></i> View Current
                        </a>
                        <span id="docHolidaysMsg" style="font-size:.82rem;color:var(--t3);"></span>
                    </div>
                    <div style="font-size:11px;color:var(--t3);margin-top:4px;">PDF, Image, or Document · Max 5 MB</div>
                </div>
                <div class="sc-field">
                    <label>Academic Calendar <span style="font-weight:400;color:var(--t3);">(reference PDF)</span></label>
                    <div style="display:flex;align-items:center;gap:10px;">
                        <input type="file" id="docAcademic" accept=".pdf,.jpg,.jpeg,.png,.gif,.webp,.doc,.docx" style="display:none;">
                        <button class="sc-btn sc-btn-ghost sc-btn-sm" onclick="document.getElementById('docAcademic').click()">
                            <i class="fa fa-upload"></i> Upload
                        </button>
                        <a id="docAcademicLink" href="#" target="_blank" style="display:none;font-size:.84rem;color:var(--gold);">
                            <i class="fa fa-download"></i> View Current
                        </a>
                        <span id="docAcademicMsg" style="font-size:.82rem;color:var(--t3);"></span>
                    </div>
                    <div style="font-size:11px;color:var(--t3);margin-top:4px;">PDF, Image, or Document · Max 5 MB</div>
                </div>
            </div>
        </div>

        <button class="sc-btn sc-btn-primary" onclick="saveProfile()">
            <i class="fa fa-save"></i> Save Profile
        </button>

        <div class="sc-card" style="margin-top:18px;">
            <div class="sc-card-title"><i class="fa fa-key"></i> Password Recovery Contact</div>
            <div style="font-size:12px;color:var(--t3);margin:-4px 0 12px;line-height:1.6;">
                Contact details used by the <strong>forgot-password</strong> flow to verify and reach the
                school super admin. Keep these accurate &mdash; recovery requests are checked against them.
            </div>
            <div class="sc-grid sc-grid-2">
                <div class="sc-field">
                    <label>Full Name</label>
                    <input type="text" id="fpd_name" maxlength="120" placeholder="Super admin full name">
                </div>
                <div class="sc-field">
                    <label>Email</label>
                    <input type="email" id="fpd_email" maxlength="120" placeholder="recovery@school.edu">
                </div>
                <div class="sc-field">
                    <label>Phone Number</label>
                    <input type="tel" id="fpd_number" maxlength="20" placeholder="e.g. +91 98765 43210">
                </div>
            </div>
            <button class="sc-btn sc-btn-primary" style="margin-top:14px;" onclick="saveForgetPwd()">
                <i class="fa fa-save"></i> Save Recovery Contact
            </button>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════ -->
    <!-- TAB: Sessions                                           -->
    <!-- ════════════════════════════════════════════════════════ -->
    <div class="sc-pane" id="tab-sessions">
        <div class="sc-step-hint">
            <span class="sc-step-badge">Step 3</span>
            Add academic sessions (e.g. 2025-26) and set the active one. All classes, students, and data are organized under sessions.
        </div>
        <div class="sc-card">
            <div class="sc-card-title" style="justify-content:space-between;">
                <span><i class="fa fa-calendar-check-o"></i> Academic Sessions</span>
                <div style="display:flex;gap:6px;">
                    <button class="sc-btn sc-btn-ghost sc-btn-sm" id="checkSessBtn" onclick="checkSessions()" title="Verify sessions list matches data in Firestore">
                        <i class="fa fa-stethoscope"></i> Consistency Check
                    </button>
                    <button class="sc-btn sc-btn-ghost sc-btn-sm" id="syncSessBtn" onclick="syncSessions()" title="Fetch latest list directly from Firebase">
                        <i class="fa fa-refresh"></i> Sync from Firebase
                    </button>
                </div>
            </div>
            <div id="sessList" class="sc-sess-list"></div>
            <div id="sessCheckResult" style="display:none;margin:8px 0 4px;padding:10px 12px;border-radius:8px;font-size:12px;"></div>
            <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                <div class="sc-field" style="width:180px;">
                    <label>New Session Year</label>
                    <input type="text" id="newSessInput" placeholder="e.g. 2026-27" maxlength="7">
                </div>
                <button class="sc-btn sc-btn-primary" onclick="addSession()" style="margin-bottom:1px;">
                    <i class="fa fa-plus"></i> Add Session
                </button>
                <button class="sc-btn sc-btn-ghost" id="suggestNextBtn" onclick="suggestNextSession()" style="margin-bottom:1px;" title="Auto-fill next academic year based on the latest session">
                    <i class="fa fa-magic"></i> Suggest Next
                </button>
                <button class="sc-btn sc-btn-warning" id="rolloverBtn" onclick="openRolloverModal()" style="margin-bottom:1px;" title="Copy class/section structure from current session to a new one">
                    <i class="fa fa-share-square-o"></i> Rollover to Next Session
                </button>
            </div>

            <!-- Rollover modal -->
            <div id="rolloverModal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:9999;align-items:center;justify-content:center;">
                <div style="background:var(--bg2);color:var(--t1);border:1px solid var(--border);border-radius:12px;max-width:680px;width:92%;max-height:88vh;overflow:auto;padding:20px;box-shadow:0 20px 50px rgba(0,0,0,.3);">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                        <h3 style="margin:0;"><i class="fa fa-share-square-o"></i> Session Rollover</h3>
                        <button class="sc-btn sc-btn-ghost sc-btn-sm" onclick="closeRolloverModal()"><i class="fa fa-times"></i></button>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:12px;">
                        <div class="sc-field" style="flex:1;min-width:160px;">
                            <label>From session</label>
                            <select id="rollFrom"></select>
                        </div>
                        <div class="sc-field" style="flex:1;min-width:160px;">
                            <label>To session</label>
                            <input type="text" id="rollTo" placeholder="e.g. 2027-28" maxlength="7">
                        </div>
                        <div style="align-self:flex-end;margin-bottom:1px;">
                            <button class="sc-btn sc-btn-ghost sc-btn-sm" onclick="rollPreview()"><i class="fa fa-eye"></i> Preview</button>
                        </div>
                    </div>

                    <div id="rollPreviewBox" style="display:none;background:var(--bg3);border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:12px;font-size:12.5px;"></div>

                    <div style="display:flex;flex-direction:column;gap:8px;padding:10px;background:var(--bg3);border-radius:8px;margin-bottom:12px;">
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;"><input type="checkbox" id="rollCopySections" checked> Copy class &amp; section structure</label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;"><input type="checkbox" id="rollPromote"> Promote students (Class N &rarr; N+1; your highest class &rarr; Alumni)</label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;"><input type="checkbox" id="rollSetActive"> Set target session as ACTIVE after rollover</label>
                    </div>
                    <div id="rollPromoteWarn" style="display:none;background:rgba(217,119,6,.12);border:1px solid rgba(217,119,6,.45);border-radius:8px;padding:10px;font-size:12px;color:#92400e;margin-bottom:12px;">
                        <b>Heads up:</b> Student promotion updates each student's <code>session</code> and <code>className</code> in place. After rollover the source session will show NO active students (historical marks/attendance/fees remain intact with their original session stamp). Students in your school&rsquo;s highest configured class complete their schooling and become <code>status=Alumni</code> &mdash; they are not moved into a new class. Which class that is comes from your Classes &amp; Sections setup, so if you are adding a higher class next year, configure it before running the rollover.
                    </div>

                    <!-- ────────────────────────────────────────────────
                         R1.4 — Step 2: Fees rollover (freeze + carry-forward)
                         Independent flow. Admin can run Step 1 (academic),
                         Step 2 (fees), or both. Each step has its own
                         preview + execute. Type-to-confirm gates Step 2.
                         ──────────────────────────────────────────────── -->
                    <div style="margin-top:6px;padding:12px;background:var(--bg3);border:1px solid var(--border);border-radius:8px;">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                            <h4 style="margin:0;font-size:14px;"><i class="fa fa-archive"></i> Step 2 — Freeze fees &amp; carry forward dues <span style="font-size:11px;color:var(--t3);font-weight:normal;">(optional, runs against the same From/To above)</span></h4>
                            <button class="sc-btn sc-btn-ghost sc-btn-sm" onclick="rollFeesPreview()"><i class="fa fa-eye"></i> Preview</button>
                        </div>
                        <div style="font-size:12px;color:var(--t2);margin-bottom:8px;">
                            Closes the source session for new payments and writes carry-forward records (unpaid balances) into the target session. Run this AFTER Step 1 or independently. Does NOT affect Razorpay verifications already in flight.
                        </div>
                        <div id="rollFeesPreviewBox" style="display:none;background:var(--bg2);border:1px solid var(--border);border-radius:8px;padding:12px;margin-bottom:8px;font-size:12.5px;"></div>
                        <div style="display:flex;justify-content:flex-end;gap:8px;">
                            <button class="sc-btn sc-btn-warn" id="rollFeesExecBtn" onclick="rollFeesExecute()" style="display:none;"><i class="fa fa-snowflake-o"></i> Freeze old session + carry forward</button>
                        </div>
                    </div>

                    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px;">
                        <button class="sc-btn sc-btn-ghost" onclick="closeRolloverModal()">Cancel</button>
                        <button class="sc-btn sc-btn-primary" id="rollExecBtn" onclick="rollExecute()"><i class="fa fa-play"></i> Execute Step 1</button>
                    </div>
                </div>
            </div>
            <div style="font-size:11px;color:var(--t3);margin-top:8px;">
                Format: <code>YYYY-YY</code> &nbsp;·&nbsp;
                Click <b>Set Active</b> to make a session the default across all modules &nbsp;·&nbsp;
                Use <b>Consistency Check</b> to detect orphaned or empty sessions &nbsp;·&nbsp;
                Use <b>Sync from Firebase</b> if you edited sessions directly in Firebase Console.
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════ -->
    <!-- TAB: Board                                              -->
    <!-- ════════════════════════════════════════════════════════ -->
    <div class="sc-pane" id="tab-board">
        <div class="sc-step-hint">
            <span class="sc-step-badge">Step 2</span>
            Select your education board (CBSE, ICSE, State Board, etc.). This determines the subject curriculum suggestions.
        </div>
        <div class="sc-card">
            <div class="sc-card-title"><i class="fa fa-university"></i> Board Type</div>
            <div class="sc-radio-group" id="boardTypeGroup">
                <?php foreach (['CBSE','ICSE','State','IB','Custom'] as $bt): ?>
                <label class="sc-radio-opt">
                    <input type="radio" name="board_type" value="<?= $bt ?>">
                    <?= $bt ?>
                </label>
                <?php endforeach; ?>
            </div>
            <div id="customBoardRow" style="margin-top:14px;display:none;">
                <div class="sc-field" style="max-width:360px;">
                    <label>Board Name</label>
                    <input type="text" id="customBoardName" placeholder="e.g. MP Board, Cambridge IGCSE" maxlength="80">
                </div>
            </div>
        </div>

        <div class="sc-card">
            <div class="sc-card-title"><i class="fa fa-bar-chart"></i> Grading Pattern</div>
            <div class="sc-radio-group" id="gradingGroup">
                <label class="sc-radio-opt">
                    <input type="radio" name="grading_pattern" value="marks"> Marks (out of 100)
                </label>
                <label class="sc-radio-opt">
                    <input type="radio" name="grading_pattern" value="grades"> Letter Grades (A+, A, B...)
                </label>
                <label class="sc-radio-opt">
                    <input type="radio" name="grading_pattern" value="cgpa"> CGPA / GPA
                </label>
            </div>

            <div class="sc-field" style="max-width:220px;margin-top:14px;">
                <label>Minimum Passing Marks (%)</label>
                <input type="number" id="passingMarks" min="0" max="100" value="33" placeholder="33">
            </div>
        </div>

        <div class="sc-card" id="gradeScaleCard" style="display:none;">
            <div class="sc-card-title"><i class="fa fa-list-ol"></i> Grade Scale</div>
            <div id="gradeScaleRows"></div>
            <button class="sc-btn sc-btn-ghost sc-btn-sm" onclick="addGradeRow()" style="margin-top:8px;">
                <i class="fa fa-plus"></i> Add Grade
            </button>
        </div>

        <button class="sc-btn sc-btn-primary" onclick="saveBoard()">
            <i class="fa fa-save"></i> Save Board Config
        </button>
    </div>

    <!-- ════════════════════════════════════════════════════════ -->
    <!-- TAB: Classes                                            -->
    <!-- ════════════════════════════════════════════════════════ -->
    <div class="sc-pane" id="tab-classes">
        <div class="sc-step-hint">
            <span class="sc-step-badge">Step 4</span>
            Define which classes your school offers (Nursery to 12th). Save the list, then activate them in the current session.
        </div>
        <div class="sc-card">
            <div class="sc-card-title" style="justify-content:space-between;">
                <span><i class="fa fa-th-large"></i> Master Class List</span>
                <label style="display:flex;align-items:center;gap:6px;font-size:11.5px;color:var(--t3);cursor:pointer;">
                    <input type="checkbox" id="showDeletedToggle" onchange="toggleDeletedClasses()"> Show Deleted
                </label>
            </div>
            <div style="font-size:12px;color:var(--t3);margin-bottom:12px;">
                Define all classes your school runs. The list is used for sections, subjects, and results.
                Toggle <b>Streams</b> for classes that have Science/Commerce/Arts streams (typically 11 &amp; 12).
            </div>
            <div class="sc-class-list" id="classList"></div>
            <div style="display:flex;gap:10px;margin-top:12px;flex-wrap:wrap;">
                <button class="sc-btn sc-btn-primary sc-btn-sm" onclick="addClassRow()">
                    <i class="fa fa-plus"></i> Add New Class
                </button>
                <button class="sc-btn sc-btn-ghost sc-btn-sm" onclick="seedDefaultClasses()">
                    <i class="fa fa-magic"></i> Seed Standard Classes (1-12 + Foundational)
                </button>
            </div>
        </div>

        <!-- ══ ADM-AGE-1 · CLASS-VS-AGE ADMISSION POLICY ══════════════════
             Lives in the Classes tab because an age band is a property of a
             class. Off until the school states a rule — ZenX does not invent
             one, because the band differs by state, by board and by the
             school's own cut-off date. -->
        <div class="sc-card" id="agePolicyCard">
            <div class="sc-card-title"><span><i class="fa fa-birthday-cake"></i> Admission Age Policy</span></div>
            <div style="font-size:12px;color:var(--t3);margin-bottom:12px;">
                Checks a student's age against the class they are being admitted to.
                Age is measured <b>as on the cut-off date</b>, the way a registrar applies it
                &mdash; a child who turns 6 the day after the cut-off is 5 for this purpose.
                Leave a class blank to exempt it.
            </div>
            <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px;">
                <div>
                    <label style="display:block;font-size:11.5px;font-weight:600;margin-bottom:4px;">Enforcement</label>
                    <select id="agePolicyMode" class="sc-input" style="min-width:210px;">
                        <option value="off">Off &mdash; do not check age</option>
                        <option value="warn">Warn &mdash; admit, but flag a mismatch</option>
                        <option value="block">Block &mdash; refuse a mismatch</option>
                    </select>
                </div>
                <div>
                    <label style="display:block;font-size:11.5px;font-weight:600;margin-bottom:4px;">Age as on (MM-DD)</label>
                    <input type="text" id="agePolicyCutoff" class="sc-input" placeholder="03-31"
                           maxlength="5" style="width:120px;">
                </div>
            </div>
            <div style="font-size:11.5px;color:var(--t3);margin-bottom:8px;">
                <b>Warn</b> is the safer default. Genuine exceptions exist &mdash; a child returning
                from abroad, a lateral entry, an RTE age-appropriate placement that is deliberately
                off-band. Blocking those trains staff to work around the system.
            </div>
            <div id="agePolicyBands"></div>
            <div style="margin-top:12px;">
                <button class="sc-btn sc-btn-primary sc-btn-sm" onclick="saveAgePolicy()">
                    <i class="fa fa-save"></i> Save Age Policy
                </button>
            </div>
        </div>

        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <button class="sc-btn sc-btn-primary" onclick="saveClasses()">
                <i class="fa fa-save"></i> Save Class List
            </button>
            <button class="sc-btn sc-btn-warning" onclick="activateClassesInSession()">
                <i class="fa fa-bolt"></i> Activate Classes in Session
            </button>
        </div>
        <div style="font-size:11px;color:var(--t3);margin-top:6px;">
            <b>Save</b> stores the master list. <b>Activate</b> creates class nodes in the active session so they appear in Manage Classes and other modules.
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════ -->
    <!-- TAB: Sections                                           -->
    <!-- ════════════════════════════════════════════════════════ -->
    <div class="sc-pane" id="tab-sections">
        <div class="sc-step-hint">
            <span class="sc-step-badge">Step 6</span>
            Assign sections (A, B, C...) to each class. For 11th &amp; 12th, sections are grouped by stream (e.g. Science A, Commerce A).
        </div>
        <div class="sc-card">
            <div class="sc-card-title" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;">
                <span><i class="fa fa-columns"></i> Section Matrix</span>
                <div style="display:flex;align-items:center;gap:10px;">
                    <div class="sc-field" style="margin:0;">
                        <select id="secSessSel" style="min-width:130px;font-size:12px;padding:5px 8px;"></select>
                    </div>
                    <button class="sc-btn sc-btn-ghost sc-btn-sm" onclick="loadAllSections()" title="Reload">
                        <i class="fa fa-refresh"></i>
                    </button>
                </div>
            </div>
            <p style="color:var(--t3);font-size:12px;margin:0 0 14px;">
                Click section letters to toggle. Green = active. Changes are tracked and saved together.
            </p>

            <!-- Bulk matrix -->
            <div id="sectionMatrixWrap" style="position:relative;min-height:80px;">
                <div id="sectionMatrixLoader" style="text-align:center;padding:30px 0;color:var(--t3);display:none;"></div>
                <div id="sectionMatrix"></div>
            </div>

            <!-- ══ PHASE 20.2 (P20.2-UI-1) — SECTION STRENGTH & OCCUPANCY ══
                 The matrix above manages which sections EXIST. This table
                 manages how many seats each one has, and shows truthful
                 occupancy computed server-side by the canonical roster rule.
                 Before this, capacity was reachable only from the legacy
                 Classes screen, which had no clickable path at all. -->
            <div id="seatsPanel" style="display:none;margin-top:22px;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:10px;">
                    <!-- Collapsible header. The whole heading is the control, so
                         the hit target is large; the summary line stays visible
                         when collapsed so occupancy totals are readable without
                         expanding. -->
                    <h4 style="margin:0;font-size:15px;color:var(--t1);">
                        <!-- Starts collapsed to match the default in
                             _seatsPrefRead(); renderSeats() re-applies the
                             operator's stored choice before revealing the panel. -->
                        <a href="#" id="seatsToggle" onclick="toggleSeats(event)"
                           role="button" aria-expanded="false" aria-controls="seatsBodyWrap"
                           style="color:inherit;text-decoration:none;display:inline-flex;align-items:center;gap:8px;">
                            <i class="fa fa-chevron-down" id="seatsChevron"
                               style="color:var(--gold);font-size:12px;width:12px;transform:rotate(-90deg);transition:transform .15s;"></i>
                            <i class="fa fa-users" style="color:var(--gold);"></i>
                            Section Strength &amp; Occupancy
                        </a>
                    </h4>
                    <span id="seatsSummary" style="font-size:12px;color:var(--t2);"></span>
                </div>
                <div style="overflow-x:auto;display:none;" id="seatsBodyWrap">
                    <table class="table table-condensed" id="seatsTable" style="margin-bottom:0;font-size:13px;">
                        <thead>
                            <tr>
                                <th style="width:22%;">Class</th>
                                <th style="width:14%;">Section</th>
                                <th style="width:12%;text-align:right;">Capacity</th>
                                <th style="width:12%;text-align:right;">Occupied</th>
                                <th style="width:12%;text-align:right;">Available</th>
                                <th style="width:16%;">Status</th>
                                <th style="width:12%;text-align:right;">Action</th>
                            </tr>
                        </thead>
                        <tbody id="seatsBody"></tbody>
                    </table>
                </div>
                <p id="seatsFootnote" style="margin:10px 2px 0;font-size:11.5px;color:var(--t3);display:none;">
                    Occupancy counts <strong>active</strong> students in the selected session only.
                    Sections showing <em>not set</em> have never had a capacity configured and fall back to 40.
                </p>
            </div>

            <!-- Capacity editor — Bootstrap 3 modal, matching the existing
                 school_config dialogs. No native alert/confirm/prompt. -->
            <div class="modal fade" id="capacityModal" tabindex="-1" role="dialog" aria-labelledby="capacityModalLabel">
              <div class="modal-dialog modal-sm" role="document">
                <div class="modal-content">
                  <div class="modal-header">
                    <button type="button" class="close" data-dismiss="modal" aria-label="Close"><span aria-hidden="true">&times;</span></button>
                    <h4 class="modal-title" id="capacityModalLabel">Section Capacity</h4>
                  </div>
                  <div class="modal-body">
                    <input type="hidden" id="capClassKey">
                    <input type="hidden" id="capSection">
                    <p style="margin:0 0 10px;font-weight:600;" id="capClassName"></p>
                    <p style="margin:0 0 4px;font-size:13px;color:var(--t2);">
                      Current capacity: <span id="capCurrent"></span>
                    </p>
                    <p style="margin:0 0 14px;font-size:13px;color:var(--t2);">
                      Currently occupied: <span id="capOccupied"></span>
                    </p>
                    <div class="form-group" style="margin-bottom:8px;">
                      <label for="capInput" style="font-size:13px;">New capacity</label>
                      <input type="number" min="1" max="500" step="1" class="form-control" id="capInput" placeholder="e.g. 40">
                    </div>
                    <div id="capWarn" class="alert alert-warning"
                         style="display:none;font-size:12.5px;padding:9px 11px;margin:10px 0 6px;"></div>
                    <div id="capConfirmRow" style="display:none;margin-bottom:4px;">
                      <label style="font-weight:400;font-size:12.5px;">
                        <input type="checkbox" id="capConfirm">
                        I understand — save this capacity anyway
                      </label>
                    </div>
                  </div>
                  <div class="modal-footer">
                    <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary btn-sm" id="capSaveBtn" onclick="saveCapacity()">Save Capacity</button>
                  </div>
                </div>
              </div>
            </div>

            <!-- Save bar -->
            <div id="secBulkBar" style="display:none;margin-top:16px;padding:12px 16px;background:var(--gold-dim);border:1px solid var(--gold);border-radius:10px;display:none;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                <span style="font-size:13px;color:var(--t1);">
                    <i class="fa fa-pencil" style="color:var(--gold);"></i>
                    <span id="secChangeCount">0</span> change(s) pending
                </span>
                <div style="display:flex;gap:8px;">
                    <button class="sc-btn sc-btn-ghost sc-btn-sm" onclick="discardSectionChanges()">Discard</button>
                    <button class="sc-btn sc-btn-primary sc-btn-sm" onclick="saveBulkSections()" id="secBulkSaveBtn">
                        <i class="fa fa-check"></i> Save All Changes
                    </button>
                </div>
            </div>
        </div>

        <!-- Quick-add custom section (for letters beyond F) -->
        <div class="sc-card" style="margin-top:0;">
            <div class="sc-card-title" style="font-size:13px;"><i class="fa fa-plus-circle"></i> Add Custom Section</div>
            <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                <div class="sc-field" style="min-width:160px;">
                    <label>Class</label>
                    <select id="secClassSel"></select>
                </div>
                <div class="sc-field" style="width:80px;">
                    <label>Letter</label>
                    <input type="text" id="newSectionInput" maxlength="1" placeholder="G"
                           style="text-transform:uppercase;text-align:center;font-weight:700;">
                </div>
                <button class="sc-btn sc-btn-primary sc-btn-sm" onclick="addSection()" style="margin-bottom:1px;">
                    <i class="fa fa-plus"></i> Add
                </button>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════ -->
    <!-- TAB: Subjects                                           -->
    <!-- ════════════════════════════════════════════════════════ -->
    <div class="sc-pane" id="tab-subjects">
        <div class="sc-step-hint">
            <span class="sc-step-badge">Step 7</span>
            Assign subjects to each class. Load defaults from your board curriculum or add them manually. For 11th &amp; 12th, subjects are stream-specific.
        </div>

        <!-- Select Class -->
        <div class="sc-card">
            <div class="sc-card-title"><i class="fa fa-book"></i> Subject Configuration</div>
            <p style="font-size:12.5px;color:var(--t3);margin:0 0 14px;">
                Select a class to configure its subjects. You'll get recommended subjects that you can edit, add to, or remove before saving.
            </p>
            <div style="display:flex;gap:10px;align-items:flex-end;flex-wrap:wrap;">
                <div class="sc-field" style="min-width:200px;">
                    <label>Select Class</label>
                    <select id="subClassSel"></select>
                </div>
                <button class="sc-btn sc-btn-primary sc-btn-sm" onclick="subLoadClass()" style="height:38px;">
                    <i class="fa fa-arrow-right"></i> Configure Subjects
                </button>
            </div>
        </div>

        <!-- Step 2: Subject Editor (hidden until class selected) -->
        <div id="subEditorWrap" style="display:none;">
            <!-- Header with class info and actions -->
            <div class="sc-card" style="padding-bottom:0;">
                <div style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:14px;">
                    <div>
                        <h4 style="margin:0;font-size:16px;font-weight:700;color:var(--t1);">
                            <i class="fa fa-book" style="color:var(--gold);margin-right:6px;"></i>
                            <span id="subEditorClassLabel"></span> — Subjects
                        </h4>
                        <div id="subEditorInfo" style="font-size:12px;color:var(--t3);margin-top:2px;"></div>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button class="sc-btn sc-btn-ghost sc-btn-sm" onclick="subResetDefaults()" title="Reset to recommended defaults">
                            <i class="fa fa-magic"></i> Reset to Defaults
                        </button>
                        <button class="sc-btn sc-btn-primary sc-btn-sm" onclick="subSaveAll()" id="subSaveBtn">
                            <i class="fa fa-save"></i> Save All Subjects
                        </button>
                    </div>
                </div>

                <!-- Stream tabs for 11-12 -->
                <div id="subStreamTabs" style="display:none;border-bottom:1px solid var(--border);margin:0 -20px;padding:0 20px;">
                    <div id="subStreamTabBar" style="display:flex;gap:0;"></div>
                </div>
            </div>

            <!-- Editable subject list -->
            <div class="sc-card" id="subEditorBody">
                <div id="subEditList"></div>

                <!-- Add new subject row -->
                <div style="margin-top:14px;padding-top:14px;border-top:1px dashed var(--border);">
                    <div style="display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;">
                        <div class="sc-field" style="flex:2;min-width:160px;">
                            <label>Subject Name</label>
                            <input type="text" id="subNewName" placeholder="e.g. Computer Science" maxlength="80">
                        </div>
                        <div class="sc-field" style="flex:1;min-width:120px;">
                            <label>Category</label>
                            <select id="subNewCat">
                                <option value="Core">Core</option>
                                <option value="Elective">Elective</option>
                                <option value="Language">Language</option>
                                <option value="Additional">Additional</option>
                                <option value="Vocational">Vocational</option>
                                <option value="Assessment">Assessment</option>
                            </select>
                        </div>
                        <div class="sc-field" id="subNewStreamWrap" style="flex:1;min-width:120px;display:none;">
                            <label>Stream</label>
                            <select id="subNewStream"></select>
                        </div>
                        <button class="sc-btn sc-btn-primary sc-btn-sm" onclick="subAddRow()" style="height:38px;white-space:nowrap;">
                            <i class="fa fa-plus"></i> Add
                        </button>
                    </div>
                </div>

                <!-- Count summary -->
                <div id="subSummary" style="margin-top:14px;padding:10px 14px;background:var(--bg3);border-radius:8px;font-size:12px;color:var(--t3);display:flex;gap:14px;flex-wrap:wrap;"></div>
            </div>
        </div>

    </div>

    <!-- ════════════════════════════════════════════════════════ -->
    <!-- TAB: Streams                                            -->
    <!-- ════════════════════════════════════════════════════════ -->
    <div class="sc-pane" id="tab-streams">
        <div class="sc-step-hint">
            <span class="sc-step-badge">Step 5</span>
            Configure streams for senior classes (11th &amp; 12th) &mdash; Science, Commerce, Arts, etc. Set up streams before creating sections.
        </div>
        <div class="sc-card">
            <div class="sc-card-title" style="justify-content:space-between;">
                <span><i class="fa fa-code-fork"></i> Stream Configuration</span>
                <button class="sc-btn sc-btn-ghost sc-btn-sm" onclick="seedStandardStreams()">
                    <i class="fa fa-magic"></i> Seed Standard Streams
                </button>
            </div>
            <div style="font-size:12px;color:var(--t3);margin-bottom:14px;">
                Streams apply to Classes 11 &amp; 12 (or any class with "Streams Enabled").
                Enable or disable streams to control which options appear in marks entry.
            </div>
            <div id="streamsList" style="margin-bottom:16px;"></div>
            <div id="streamsSaveBar" style="display:none;margin-bottom:14px;">
                <button class="sc-btn sc-btn-primary" onclick="saveAllStreams()">
                    <i class="fa fa-save"></i> Save All Streams
                </button>
            </div>

            <!-- Add Stream Form -->
            <div style="padding-top:14px;border-top:1px solid var(--border);">
                <div class="sc-card-title" style="margin-bottom:12px;font-size:13px;">
                    <i class="fa fa-plus-circle"></i> Add / Update Stream
                </div>
                <div class="sc-grid sc-grid-2" style="max-width:460px;">
                    <div class="sc-field">
                        <label>Stream Key (no spaces)</label>
                        <input type="text" id="newStreamKey" placeholder="e.g. Science" maxlength="30">
                    </div>
                    <div class="sc-field">
                        <label>Display Label</label>
                        <input type="text" id="newStreamLabel" placeholder="e.g. Science" maxlength="60">
                    </div>
                </div>
                <div style="display:flex;align-items:center;gap:10px;margin-top:10px;">
                    <label style="display:flex;align-items:center;gap:7px;cursor:pointer;font-size:13px;color:var(--t2);">
                        <input type="checkbox" id="newStreamEnabled" checked>
                        Enabled
                    </label>
                    <button type="button" class="sc-btn sc-btn-primary" onclick="saveStream()">
                        <i class="fa fa-save"></i> Save Stream
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════ -->
    <!-- TAB: Report Card Template                                -->
    <!-- ════════════════════════════════════════════════════════ -->
    <div class="sc-pane" id="tab-reportcard">
        <div class="sc-step-hint">
            <span class="sc-step-badge">Step 8</span>
            Choose a report card template. This is the final step &mdash; your school is now fully configured!
        </div>
        <div class="sc-card">
            <div class="sc-card-title"><i class="fa fa-file-text-o"></i> Report Card Template</div>
            <div style="font-size:12px;color:var(--t3);margin-bottom:18px;">
                Choose a report card layout for your school. This template will be used when printing student report cards.
            </div>

            <div class="rct-grid" id="rcTemplateGrid">
                <!-- Classic -->
                <div class="rct-card" data-tpl="classic">
                    <div class="rct-preview rct-preview-classic">
                        <div class="rct-mini-header" style="background:#14532d"></div>
                        <div class="rct-mini-row"></div><div class="rct-mini-row"></div><div class="rct-mini-row"></div>
                        <div class="rct-mini-footer" style="background:#fdebd0"></div>
                    </div>
                    <div class="rct-name">Classic</div>
                    <div class="rct-desc">Traditional green/teal school format with detailed component columns. Original default.</div>
                </div>
                <!-- CBSE -->
                <div class="rct-card" data-tpl="cbse">
                    <div class="rct-preview rct-preview-cbse">
                        <div class="rct-mini-header" style="background:#1a237e"></div>
                        <div class="rct-mini-row"></div><div class="rct-mini-row"></div><div class="rct-mini-row"></div>
                        <div class="rct-mini-footer" style="background:#283593"></div>
                    </div>
                    <div class="rct-name">CBSE</div>
                    <div class="rct-desc">Official CBSE CCE-style with blue/navy theme, scholastic &amp; co-scholastic sections.</div>
                </div>
                <!-- Minimal -->
                <div class="rct-card" data-tpl="minimal">
                    <div class="rct-preview rct-preview-minimal">
                        <div class="rct-mini-header" style="background:#2563eb;height:6px"></div>
                        <div class="rct-mini-row" style="border-color:#e5e7eb"></div>
                        <div class="rct-mini-row" style="border-color:#e5e7eb"></div>
                        <div class="rct-mini-row" style="border-color:#e5e7eb"></div>
                        <div class="rct-mini-footer" style="background:#f3f4f6"></div>
                    </div>
                    <div class="rct-name">Minimal</div>
                    <div class="rct-desc">Ultra-clean, modern design. Monochrome with blue accent. Compact and readable.</div>
                </div>
                <!-- Modern -->
                <div class="rct-card" data-tpl="modern">
                    <div class="rct-preview rct-preview-modern">
                        <div class="rct-mini-header" style="background:linear-gradient(135deg,#667eea,#764ba2)"></div>
                        <div class="rct-mini-row" style="border-radius:4px;margin:3px"></div>
                        <div class="rct-mini-row" style="border-radius:4px;margin:3px"></div>
                        <div class="rct-mini-footer" style="background:linear-gradient(135deg,#667eea,#764ba2);height:8px"></div>
                    </div>
                    <div class="rct-name">Modern</div>
                    <div class="rct-desc">Card-based layout with gradients, progress bars, and metric dashboard.</div>
                </div>
                <!-- Elegant -->
                <div class="rct-card" data-tpl="elegant">
                    <div class="rct-preview rct-preview-elegant" style="border:2px double #8B6914;background:#FFFDF5">
                        <div class="rct-mini-header" style="background:#8B6914;height:10px"></div>
                        <div class="rct-mini-row" style="background:#FFF8E7"></div>
                        <div class="rct-mini-row" style="background:#FFFDF5"></div>
                        <div class="rct-mini-footer" style="background:#8B6914"></div>
                    </div>
                    <div class="rct-name">Elegant</div>
                    <div class="rct-desc">Premium certificate-style with serif fonts, gold accents, and decorative borders.</div>
                </div>
                <!-- Professional -->
                <div class="rct-card" data-tpl="professional">
                    <div class="rct-preview rct-preview-professional">
                        <div class="rct-mini-header" style="background:#1a3a6b;height:6px"></div>
                        <div class="rct-mini-header" style="background:#1a3a6b;height:9px;margin-top:2px"></div>
                        <div class="rct-mini-row"></div><div class="rct-mini-row"></div><div class="rct-mini-row"></div>
                        <div class="rct-mini-footer" style="background:#eef2f7"></div>
                    </div>
                    <div class="rct-name">Professional <span style="display:inline-block;background:#1a3a6b;color:#fff;font-size:8px;font-weight:700;padding:1px 5px;border-radius:3px;vertical-align:middle;letter-spacing:.5px">CUSTOMIZABLE</span></div>
                    <div class="rct-desc">Clean corporate layout that fully adapts to the customization options below — accent color, sections, text &amp; signatures.</div>
                </div>
            </div>

            <div style="margin-top:18px;display:flex;align-items:center;gap:14px;">
                <button class="sc-btn sc-btn-gold" id="btnSaveRcTemplate">
                    <i class="fa fa-save"></i> Save Template
                </button>
                <span id="rcCurrentLabel" style="font-size:12px;color:var(--t3);">Current: Classic</span>
            </div>
        </div>

        <!-- ════════ CUSTOMIZE REPORT CARD ════════ -->
        <div class="sc-card">
            <div class="sc-card-title"><i class="fa fa-paint-brush"></i> Customize Report Card</div>
            <div style="font-size:12px;color:var(--t3);margin-bottom:18px;">
                Fine-tune the report card to match your school's branding. These settings apply on top of the selected template.
                The <strong>Professional</strong> template reflects every option below; preset templates honor the section toggles, custom text &amp; signatures.
            </div>

            <div class="rcc-layout">
                <!-- ── Controls column ── -->
                <div class="rcc-controls">

                    <!-- Accent colour -->
                    <div class="rcc-group">
                        <div class="rcc-group-title">Accent Color</div>
                        <div class="rcc-color-row">
                            <input type="color" id="rccAccent" value="#1a3a6b">
                            <input type="text" id="rccAccentHex" class="rcc-hex" value="#1a3a6b" maxlength="7" spellcheck="false">
                            <div class="rcc-swatches" id="rccSwatches">
                                <button type="button" class="rcc-sw" data-c="#1a3a6b" style="background:#1a3a6b" title="Navy"></button>
                                <button type="button" class="rcc-sw" data-c="#BC5A3C" style="background:#BC5A3C" title="Teal"></button>
                                <button type="button" class="rcc-sw" data-c="#7c2d12" style="background:#7c2d12" title="Maroon"></button>
                                <button type="button" class="rcc-sw" data-c="#4338ca" style="background:#4338ca" title="Indigo"></button>
                                <button type="button" class="rcc-sw" data-c="#166534" style="background:#166534" title="Green"></button>
                                <button type="button" class="rcc-sw" data-c="#9a3412" style="background:#9a3412" title="Rust"></button>
                                <button type="button" class="rcc-sw" data-c="#1f2937" style="background:#1f2937" title="Charcoal"></button>
                            </div>
                        </div>
                    </div>

                    <!-- Section toggles -->
                    <div class="rcc-group">
                        <div class="rcc-group-title">Sections</div>
                        <div class="rcc-toggles">
                            <label class="rcc-toggle"><input type="checkbox" id="rccSec_photo" checked><span>Student Photo</span></label>
                            <label class="rcc-toggle"><input type="checkbox" id="rccSec_resultStrip" checked><span>Result Summary Strip</span></label>
                            <label class="rcc-toggle"><input type="checkbox" id="rccSec_gradingLegend" checked><span>Grading Legend</span></label>
                            <label class="rcc-toggle"><input type="checkbox" id="rccSec_coScholastic" checked><span>Co-Scholastic Areas</span></label>
                            <label class="rcc-toggle"><input type="checkbox" id="rccSec_attendance" checked><span>Attendance &amp; Remarks</span></label>
                            <label class="rcc-toggle"><input type="checkbox" id="rccSec_signatures" checked><span>Signatures</span></label>
                        </div>
                    </div>

                    <!-- Custom text -->
                    <div class="rcc-group">
                        <div class="rcc-group-title">Custom Text</div>
                        <div class="rcc-fields">
                            <div class="rcc-field"><label>Report Title</label><input type="text" id="rccTxt_title" maxlength="80" placeholder="e.g. Academic Report Card"></div>
                            <div class="rcc-field"><label>Principal Name</label><input type="text" id="rccTxt_principalName" maxlength="80" placeholder="Shown above Principal signature"></div>
                            <div class="rcc-field"><label>Exam Controller Name</label><input type="text" id="rccTxt_examControllerName" maxlength="80" placeholder="Optional"></div>
                            <div class="rcc-field"><label>Class Teacher Name</label><input type="text" id="rccTxt_classTeacherName" maxlength="80" placeholder="Optional"></div>
                            <div class="rcc-field rcc-field-wide"><label>Default Teacher Remark</label><input type="text" id="rccTxt_defaultRemark" maxlength="240" placeholder="Optional default remark text"></div>
                            <div class="rcc-field rcc-field-wide"><label>Footer Line</label><input type="text" id="rccTxt_footer" maxlength="240" placeholder="This is a computer-generated report card..."></div>
                        </div>
                    </div>

                    <!-- Asset uploads -->
                    <div class="rcc-group">
                        <div class="rcc-group-title">Logo &amp; Signature Images</div>
                        <div class="rcc-assets">
                            <div class="rcc-asset" data-slot="logoUrl">
                                <div class="rcc-asset-prev" id="rccAsset_logoUrl"><span class="rcc-asset-ph">No image</span></div>
                                <div class="rcc-asset-label">Custom Logo</div>
                                <div class="rcc-asset-btns">
                                    <label class="rcc-mini-btn">Upload<input type="file" accept="image/*" data-slot="logoUrl" class="rcc-file" hidden></label>
                                    <button type="button" class="rcc-mini-btn rcc-mini-rm" data-slot="logoUrl">Remove</button>
                                </div>
                            </div>
                            <div class="rcc-asset" data-slot="principalSignUrl">
                                <div class="rcc-asset-prev" id="rccAsset_principalSignUrl"><span class="rcc-asset-ph">No image</span></div>
                                <div class="rcc-asset-label">Principal Signature</div>
                                <div class="rcc-asset-btns">
                                    <label class="rcc-mini-btn">Upload<input type="file" accept="image/*" data-slot="principalSignUrl" class="rcc-file" hidden></label>
                                    <button type="button" class="rcc-mini-btn rcc-mini-rm" data-slot="principalSignUrl">Remove</button>
                                </div>
                            </div>
                            <div class="rcc-asset" data-slot="classTeacherSignUrl">
                                <div class="rcc-asset-prev" id="rccAsset_classTeacherSignUrl"><span class="rcc-asset-ph">No image</span></div>
                                <div class="rcc-asset-label">Teacher Signature</div>
                                <div class="rcc-asset-btns">
                                    <label class="rcc-mini-btn">Upload<input type="file" accept="image/*" data-slot="classTeacherSignUrl" class="rcc-file" hidden></label>
                                    <button type="button" class="rcc-mini-btn rcc-mini-rm" data-slot="classTeacherSignUrl">Remove</button>
                                </div>
                            </div>
                        </div>
                        <div class="rcc-asset-note">PNG/JPG/WEBP up to 2&nbsp;MB. Transparent PNG works best for signatures. Uploads save immediately.</div>
                    </div>

                    <div style="margin-top:6px;display:flex;align-items:center;gap:14px;">
                        <button class="sc-btn sc-btn-gold" id="btnSaveRcConfig">
                            <i class="fa fa-save"></i> Save Customization
                        </button>
                        <button class="sc-btn" id="btnResetRcConfig" style="background:var(--bg2);border:1px solid var(--border);color:var(--t2);">
                            <i class="fa fa-undo"></i> Reset to Default
                        </button>
                        <span id="rccSaveMsg" style="font-size:12px;color:var(--t3);"></span>
                    </div>
                </div>

                <!-- ── Live preview column ── -->
                <div class="rcc-preview-col">
                    <div class="rcc-preview-bar">
                        <i class="fa fa-eye"></i> Live Preview
                    </div>
                    <div class="rcc-preview-frame" id="rccPreview"><!-- JS-rendered --></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ════════════════════════════════════════════════════════ -->
    <!-- TAB: Organisation (Phase 1 ZenX HRMS)                    -->
    <!-- ════════════════════════════════════════════════════════ -->
    <div class="sc-pane" id="tab-organisation">
        <div class="sc-card">
            <h4 style="margin-top:0;">Organisation type &amp; terminology</h4>
            <p style="color:var(--t3); font-size:12px;">Changing terminology never changes how the underlying data works — it only relabels what's shown on screen.</p>
            <div style="display:flex; gap:16px; flex-wrap:wrap; margin-bottom:16px;">
                <div>
                    <label style="display:block; font-size:11px; color:var(--t3); margin-bottom:4px;">Organisation type</label>
                    <select id="orgTypeSelect" class="form-control" style="width:220px;">
                        <option value="EDUCATION">Education</option>
                        <option value="CORPORATE">Corporate</option>
                        <option value="RETAIL">Retail</option>
                    </select>
                </div>
                <button class="btn btn-primary" id="orgTypeSaveBtn" style="align-self:flex-end; height:34px;">Save type</button>
            </div>
            <div style="display:flex; gap:16px; flex-wrap:wrap; align-items:flex-end;">
                <div>
                    <label style="display:block; font-size:11px; color:var(--t3); margin-bottom:4px;">"Staff" is called</label>
                    <input type="text" id="orgTermPerson" class="form-control" style="width:180px;" maxlength="60">
                </div>
                <div>
                    <label style="display:block; font-size:11px; color:var(--t3); margin-bottom:4px;">"Campus" is called</label>
                    <input type="text" id="orgTermLocation" class="form-control" style="width:180px;" maxlength="60">
                </div>
                <div>
                    <label style="display:block; font-size:11px; color:var(--t3); margin-bottom:4px;">"Principal / HOD" is called</label>
                    <input type="text" id="orgTermManager" class="form-control" style="width:180px;" maxlength="60">
                </div>
                <button class="btn btn-primary" id="orgTermSaveBtn" style="height:34px;">Save terminology</button>
            </div>
        </div>

        <div class="sc-card">
            <h4 style="margin-top:0;" id="orgLocationsHeading">Locations</h4>
            <div style="display:flex; gap:8px; margin-bottom:14px;">
                <input type="text" id="orgLocationNewName" class="form-control" style="max-width:280px;" placeholder="e.g. North Campus" maxlength="120">
                <button class="btn btn-primary" id="orgLocationAddBtn">Add</button>
            </div>
            <p class="text-muted" style="margin:-6px 0 12px; font-size:12px;">
                ESI applies in areas the government has notified, so a school with more than one
                campus can legitimately have one covered and another not. Leave a location on
                <em>Use school setting</em> unless you know otherwise &mdash; it then follows the
                statutory configuration rather than being switched off.
            </p>
            <table class="table" id="orgLocationsTable">
                <thead><tr><th>Name</th><th>Default</th><th style="width:190px;">ESI applicable</th><th style="width:160px;">Actions</th></tr></thead>
                <tbody id="orgLocationsBody"><!-- JS-rendered --></tbody>
            </table>
        </div>
    </div>

</div><!-- /.sc-wrap -->
</div><!-- /.content-wrapper -->

<div id="scToast"></div>

<script src="<?= base_url('assets/js/india_geo.js') ?>"></script>
<script>
(function () {
'use strict';

var BASE = '<?= base_url() ?>';
var CSRFN = '<?= $this->security->get_csrf_token_name() ?>';
var CSRFT = '<?= $this->security->get_csrf_hash() ?>';
var currentSession = '<?= htmlspecialchars($session_year ?? '', ENT_QUOTES, 'UTF-8') ?>';

/* ── Toast ─────────────────────────────────────────────────── */
var toastTimer;
function toast(msg, ok) {
    var el = document.getElementById('scToast');
    el.textContent = msg;
    el.className   = 'show ' + (ok === false ? 'err' : 'ok');
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.className = ''; }, 3500);
}

/* ── Tab switching ──────────────────────────────────────────── */
document.getElementById('scTabs').addEventListener('click', function(e) {
    var btn = e.target.closest('.sc-tab');
    if (!btn) return;
    document.querySelectorAll('.sc-tab').forEach(function(b) { b.classList.remove('active'); });
    document.querySelectorAll('.sc-pane').forEach(function(p) { p.classList.remove('active'); });
    btn.classList.add('active');
    var pane = document.getElementById('tab-' + btn.dataset.tab);
    if (pane) pane.classList.add('active');

    if (btn.dataset.tab === 'sessions') {
        syncSessions(true);   // silent background refresh — no blocking overlay on tab switch
    }
    if (btn.dataset.tab === 'sections') {
        loadAllSections();    // uses its own inline (scoped) loader, not the overlay
    }
    if (btn.dataset.tab === 'onboarding') {
        loadOnboardingTasks();
    }
    if (btn.dataset.tab === 'notifications') {
        // Re-read on every visit rather than caching: a preference changed in
        // another session must not keep showing as its old value here.
        loadNotificationSettings();
    }
});

/* ── Deep link: open a tab from the URL hash ─────────────────
   Other screens link straight to a tab — e.g. Student Admission's
   "Change capacity" points at school_config#sections, because capacity
   lives on the Sections tab and used to send the operator to the legacy
   per-class page instead.

   Without this the link lands on the DEFAULT tab and the operator has to
   hunt for Sections, which is the same dead end in a nicer wrapper. Reuses
   the existing click handler by clicking the button rather than
   duplicating the pane/loader logic — one tab-activation path, not two. */
(function openTabFromHash() {
    var want = (window.location.hash || '').replace(/^#/, '').trim();
    if (!want) return;
    var btn = document.querySelector('.sc-tab[data-tab="' + want.replace(/"/g, '') + '"]');
    if (btn) btn.click();
})();

/* ── POST helper ────────────────────────────────────────────── */
function _readCsrfCookie() {
    var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + CSRFN + '=([^;]+)'));
    return match ? decodeURIComponent(match[1]) : CSRFT;
}
/* ── Onboarding checklist (per school) ─────────────────────────────────
   Whole-list replace: the list is short, its order is meaningful to whoever
   works the checklist, and replacing makes the change one before/after rather
   than a stream of moves. */
var onbSaving = false;

function loadOnboardingTasks() {
    var load = document.getElementById('onbLoading');
    var err  = document.getElementById('onbError');
    var ed   = document.getElementById('onbEditor');
    if (!ed) return;

    load.style.display = '';
    err.style.display  = 'none';
    ed.style.display   = 'none';

    post('school_config/get_onboarding_tasks', {}, function (r) {
        load.style.display = 'none';

        if (!r || r.status !== 'success') {
            document.getElementById('onbErrorText').textContent =
                (r && r.message) || 'Could not load the checklist.';
            err.style.display = '';
            return;
        }

        renderOnboardingRows(r.tasks || []);
        document.getElementById('onbUsingDefault').style.display = r.usingDefault ? '' : 'none';
        ed.style.display = '';
    });
}

function renderOnboardingRows(tasks) {
    var wrap = document.getElementById('onbRows');
    wrap.innerHTML = '';
    if (!tasks.length) { addOnboardingRow(''); return; }
    tasks.forEach(function (t) { addOnboardingRow(t); });
}

function addOnboardingRow(value) {
    var wrap = document.getElementById('onbRows');
    var row  = document.createElement('div');
    row.style.cssText = 'display:flex;gap:8px;align-items:center;margin-bottom:8px;';
    row.innerHTML =
        '<input type="text" class="onb-task" maxlength="120" placeholder="e.g. ID card issued" '
      + 'style="flex:1;padding:8px 10px;font-size:13px;border:1px solid var(--border);'
      + 'border-radius:6px;background:var(--bg);color:var(--t1);">'
      + '<button class="sc-btn onb-del" title="Remove this task"><i class="fa fa-times"></i></button>';
    wrap.appendChild(row);
    row.querySelector('.onb-task').value = value || '';
}

document.addEventListener('click', function (ev) {
    if (!ev.target.closest) return;

    if (ev.target.closest('#onbAddRow')) { addOnboardingRow(''); return; }

    var del = ev.target.closest('.onb-del');
    if (del) {
        var rows = document.querySelectorAll('#onbRows > div');
        // Never leave the editor with nothing to type into.
        if (rows.length <= 1) { del.parentNode.querySelector('.onb-task').value = ''; return; }
        del.parentNode.remove();
        return;
    }

    if (ev.target.closest('#onbSave')) {
        if (onbSaving) return;

        var tasks = [];
        document.querySelectorAll('#onbRows .onb-task').forEach(function (i) {
            var v = (i.value || '').trim();
            if (v) tasks.push(v);
        });

        if (!tasks.length && !confirm('Save an empty checklist?\n\n'
                + 'The built-in checklist will be used instead.')) return;

        onbSaving = true;
        var btn = document.getElementById('onbSave');
        var st  = document.getElementById('onbStatus');
        btn.disabled = true;
        st.textContent = 'Saving...';

        post('school_config/save_onboarding_tasks', { tasks: JSON.stringify(tasks) }, function (r) {
            onbSaving = false;
            btn.disabled = false;

            if (!r || r.status !== 'success') {
                st.textContent = '';
                alert((r && r.message) || 'Could not save the checklist.');
                return;
            }
            st.textContent = (r.message || 'Saved.') + ' ' + (r.note || '');
            loadOnboardingTasks();          // re-read; never assume
        });
    }
});
/* ── Notifications (per school) ─────────────────────────────────────────
   Reads and writes one event at a time: a per-event write is a per-event
   audit row, which is what makes "who switched the fee reminders off, and
   when" answerable later. */
var notifBusy = {};

function loadNotificationSettings() {
    var load = document.getElementById('notifLoading');
    var err  = document.getElementById('notifError');
    var body = document.getElementById('notifGroups');
    if (!body) return;

    load.style.display = '';
    err.style.display  = 'none';
    body.style.display = 'none';

    post('school_config/get_notification_settings', {}, function (r) {
        load.style.display = 'none';

        if (!r || r.status !== 'success' || !r.groups) {
            document.getElementById('notifErrorText').textContent =
                (r && r.message) || 'Could not load notification settings.';
            err.style.display = '';
            return;
        }

        renderNotificationSettings(r.groups);
        body.style.display = '';
    });
}

function renderNotificationSettings(groups) {
    var body = document.getElementById('notifGroups');
    var html = '';

    if (!groups.length) {
        body.innerHTML = '<div style="padding:24px;text-align:center;color:var(--t3);">'
                       + 'No configurable notifications.</div>';
        return;
    }

    groups.forEach(function (g) {
        html += '<div style="margin-bottom:22px;">'
             +  '<div style="font-size:12px;font-weight:700;text-transform:uppercase;'
             +  'letter-spacing:.4px;color:var(--t3);margin-bottom:8px;">' + scEsc(g.group) + '</div>'
             +  '<div style="overflow-x:auto;"><table class="sc-table"><tbody>';

        (g.events || []).forEach(function (e) {
            var meta = e.updatedBy
                ? ('<div style="font-size:11px;color:var(--t3);margin-top:2px;">Last changed by '
                    + scEsc(e.updatedBy) + (e.updatedAt ? ' on ' + scEsc(String(e.updatedAt).substring(0, 10)) : '')
                    + '</div>')
                : '';

            html += '<tr>'
                 +  '<td>' + scEsc(e.label) + meta + '</td>'
                 +  '<td style="width:150px;text-align:right;">'
                 +    '<button class="sc-btn" data-notif-mark="' + scEsc(e.mark) + '" '
                 +      'data-notif-next="' + (e.enabled ? '0' : '1') + '">'
                 +      (e.enabled
                            ? '<i class="fa fa-bell"></i> On'
                            : '<i class="fa fa-bell-slash-o"></i> Off')
                 +    '</button>'
                 +  '</td>'
                 + '</tr>';
        });

        html += '</tbody></table></div></div>';
    });

    body.innerHTML = html;
}

/** Minimal escaper — this renders operator-authored text into innerHTML. */
function scEsc(v) {
    var d = document.createElement('div');
    d.textContent = (v == null ? '' : String(v));
    return d.innerHTML;
}

document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-notif-mark]') : null;
    if (!btn) return;

    var mark = btn.getAttribute('data-notif-mark');
    if (notifBusy[mark]) return;                 // no double-fire

    var next = btn.getAttribute('data-notif-next') === '1';

    // Switching OFF stops the alert for everyone in the school, so it is
    // confirmed. Switching back on is not.
    if (!next && !confirm('Stop sending this notification?\n\n'
            + 'Nobody in this school will receive it until it is switched back on.')) {
        return;
    }

    notifBusy[mark] = true;
    var original = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving';

    post('school_config/save_notification_setting',
        { mark: mark, enabled: next ? '1' : '0' },
        function (r) {
            notifBusy[mark] = false;
            btn.disabled = false;

            if (!r || r.status !== 'success') {
                btn.innerHTML = original;        // never show a state we did not save
                alert((r && r.message) || 'Could not save. Please try again.');
                return;
            }
            // Re-read rather than flipping the label locally: the row also
            // carries who changed it and when.
            loadNotificationSettings();
        });
});
function post(url, data, cb, opts) {
    // Always read the latest CSRF token from the cookie to avoid stale-token errors
    // (e.g., when switching between SA panel and school panel tabs)
    CSRFT = _readCsrfCookie();
    data[CSRFN] = CSRFT;
    var body = Object.keys(data).map(function(k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
    }).join('&');

    // 2026-06-09 — Centralised ZenXii loading animation for every school_config
    // AJAX call (all tabs). Ref-counted ZXLoader.show/hide pair so concurrent or
    // nested posts behave. Pass {loader:false} to suppress (silent background
    // calls), or {loader:'message…'} for a custom overlay message.
    opts = opts || {};
    var useLoader = (opts.loader !== false) && !!window.ZXLoader;
    var loaderMsg = (typeof opts.loader === 'string') ? opts.loader : 'Loading…';
    var loaderDone = false;
    function hideLoader() { if (useLoader && !loaderDone) { loaderDone = true; ZXLoader.hide(); } }
    if (useLoader) ZXLoader.show(loaderMsg);

    fetch(BASE + url, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-CSRF-Token': CSRFT,
            'X-Requested-With': 'XMLHttpRequest',
        },
        body: body,
    })
    .then(function(r) {
        if (!r.ok && r.status === 403) {
            // CSRF token likely expired/mismatched — refresh and retry once
            return r.text().then(function(txt) {
                if (txt.indexOf('action is not allowed') !== -1 || txt.indexOf('csrf') !== -1) {
                    throw new Error('CSRF token expired. Please reload the page.');
                }
                throw new Error('Access denied (HTTP 403).');
            });
        }
        var ct = r.headers.get('content-type') || '';
        if (ct.indexOf('application/json') === -1) {
            return r.text().then(function(txt) {
                console.error('Non-JSON response from ' + url + ':', txt.substring(0, 500));
                throw new Error('Server error (HTTP ' + r.status + '). Check console for details.');
            });
        }
        return r.json();
    })
    .then(function(d) { hideLoader(); if (d.csrf_token) CSRFT = d.csrf_token; cb(d); })
    .catch(function(e) {
        hideLoader();
        console.error('POST ' + url + ' failed:', e);
        var msg = (e && e.message) ? e.message : 'Network error — check console for details.';
        cb({ status: 'error', message: msg });
    });
}

/* ── Load all config on init ────────────────────────────────── */
var CFG = {};
function loadConfig() {
    post('school_config/get_config', {}, function(d) {
        if (d.status !== 'success') { toast('Failed to load config.', false); return; }
        CFG = d;
        CFG.archived_sessions = d.archived_sessions || [];
        CSRFT = d.csrf_token || CSRFT;
        renderProfile(d.profile || {});
        renderForgetPwd(d.forget_password_details || {});
        renderSessions(d.sessions || [], d.active_session || '');
        renderBoard(d.board || {});
        renderClasses(d.classes || []);
        // ADM-AGE-1 — drawn from the same class list, so the two can never show a
        // different set of classes.
        renderAgePolicy(d.classes || [], d.admission_age_policy || {});
        renderClassSelects(d.classes || [], d.sessions || [], d.active_session || '');
        var streams = (d.streams && typeof d.streams === 'object' && !Array.isArray(d.streams)) ? d.streams : {};
        CFG.streams = streams;
        renderStreams(streams);
        populateStreamDropdown(streams);
        renderReportCardTemplate(d.report_card_template || 'classic');
        renderRcConfig(d.report_card_config || {});
        renderOrganisation(d.organisation || {});
    });
}


/* ══════════ ORGANISATION (Phase 1 ZenX HRMS) ══════════
 * Entirely capability-gated on the client too, mirroring the server: when
 * hrms_capability_enabled is false the tab stays hidden and none of this
 * fires an extra request — a tenant without the capability sees exactly
 * the page it always saw. */
function renderOrganisation(org) {
    var tabBtn = document.getElementById('scTabOrganisation');
    if (!org || !org.hrms_capability_enabled) {
        if (tabBtn) tabBtn.style.display = 'none';
        return;
    }
    if (tabBtn) tabBtn.style.display = '';

    var typeEl = document.getElementById('orgTypeSelect');
    if (typeEl) typeEl.value = org.org_type || 'EDUCATION';

    var terms = org.terms || {};
    var pEl = document.getElementById('orgTermPerson');
    var lEl = document.getElementById('orgTermLocation');
    var mEl = document.getElementById('orgTermManager');
    if (pEl) pEl.value = terms.person || '';
    if (lEl) lEl.value = terms.location || '';
    if (mEl) mEl.value = terms.manager || '';

    var headingEl = document.getElementById('orgLocationsHeading');
    if (headingEl) headingEl.textContent = terms.location ? (terms.location + 's') : 'Locations';

    loadLocations();
}

function saveOrgType() {
    var val = document.getElementById('orgTypeSelect').value;
    post('school_config/save_profile', { org_type: val }, function(d) {
        toast(d.message || (d.status === 'success' ? 'Saved.' : 'Failed.'), d.status === 'success');
        if (d.status === 'success') loadConfig();
    });
}

function saveTerminology() {
    post('school_config/save_terminology', {
        person:   document.getElementById('orgTermPerson').value,
        location: document.getElementById('orgTermLocation').value,
        manager:  document.getElementById('orgTermManager').value,
    }, function(d) {
        toast(d.message || (d.status === 'success' ? 'Saved.' : 'Failed.'), d.status === 'success');
        if (d.status === 'success') loadConfig();
    });
}

function loadLocations() {
    post('workforce_locations/get_data', {}, function(d) {
        if (d.status !== 'success') { toast(d.message || 'Failed to load locations.', false); return; }
        renderLocationsTable(d.locations || []);
    }, { loader: false });
}

function renderLocationsTable(locations) {
    var body = document.getElementById('orgLocationsBody');
    if (!body) return;
    body.innerHTML = '';
    locations.forEach(function(loc) {
        var tr = document.createElement('tr');

        var nameTd = document.createElement('td');
        var nameInput = document.createElement('input');
        nameInput.type = 'text';
        nameInput.className = 'form-control input-sm';
        nameInput.value = loc.name;
        nameInput.maxLength = 120;
        nameTd.appendChild(nameInput);

        var defaultTd = document.createElement('td');
        defaultTd.textContent = loc.isDefault ? 'Yes' : '';

        // ESI applicability. TRI-STATE: '' means the school has not decided,
        // and an undecided location INHERITS the tenant-wide statutory setting.
        // A two-state control would force every location to be explicitly on or
        // off the moment this screen shipped.
        var esiTd = document.createElement('td');
        var esiSel = document.createElement('select');
        esiSel.className = 'form-control input-sm';
        [['', 'Use school setting'], ['1', 'Applicable here'], ['0', 'Not applicable here']]
            .forEach(function (o) {
                var opt = document.createElement('option');
                opt.value = o[0];
                opt.textContent = o[1];
                esiSel.appendChild(opt);
            });
        esiSel.value = (loc.esiApplicable === true) ? '1'
                     : (loc.esiApplicable === false) ? '0' : '';
        esiTd.appendChild(esiSel);

        var actionsTd = document.createElement('td');
        var saveBtn = document.createElement('button');
        saveBtn.className = 'btn btn-xs btn-primary';
        saveBtn.textContent = 'Save';
        saveBtn.style.marginRight = '6px';
        saveBtn.onclick = function() {
            saveLocation(loc.locationId, nameInput.value, esiSel.value);
        };
        actionsTd.appendChild(saveBtn);

        if (!loc.isDefault) {
            var delBtn = document.createElement('button');
            delBtn.className = 'btn btn-xs btn-danger';
            delBtn.textContent = 'Delete';
            delBtn.onclick = function() { deleteLocation(loc.locationId); };
            actionsTd.appendChild(delBtn);
        }

        tr.appendChild(nameTd);
        tr.appendChild(defaultTd);
        tr.appendChild(esiTd);
        tr.appendChild(actionsTd);
        body.appendChild(tr);
    });
}

function addLocation() {
    var nameEl = document.getElementById('orgLocationNewName');
    var name = nameEl.value.trim();
    if (!name) { toast('Enter a name first.', false); return; }
    post('workforce_locations/save', { name: name }, function(d) {
        toast(d.message || (d.status === 'success' ? 'Added.' : 'Failed.'), d.status === 'success');
        if (d.status === 'success') { nameEl.value = ''; loadLocations(); }
    });
}

function saveLocation(locationId, name, esiApplicable) {
    name = (name || '').trim();
    if (!name) { toast('Name cannot be empty.', false); return; }
    // esi_applicable is ALWAYS sent from this screen, including as '' for
    // "use the school setting". The server treats an ABSENT field as "leave it
    // alone", so omitting it here would make the determination unchangeable
    // back to undecided once it had been set.
    post('workforce_locations/save', {
        location_id: locationId,
        name: name,
        esi_applicable: (esiApplicable === undefined || esiApplicable === null) ? '' : esiApplicable
    }, function(d) {
        toast(d.message || (d.status === 'success' ? 'Saved.' : 'Failed.'), d.status === 'success');
        if (d.status === 'success') loadLocations();
    });
}

function deleteLocation(locationId) {
    if (!confirm('Delete this location? Staff assigned to it must be reassigned first.')) return;
    post('workforce_locations/delete', { location_id: locationId }, function(d) {
        toast(d.message || (d.status === 'success' ? 'Deleted.' : 'Failed.'), d.status === 'success');
        if (d.status === 'success') loadLocations();
    });
}

document.addEventListener('DOMContentLoaded', function() {
    var t = document.getElementById('orgTypeSaveBtn');       if (t) t.addEventListener('click', saveOrgType);
    var tm = document.getElementById('orgTermSaveBtn');      if (tm) tm.addEventListener('click', saveTerminology);
    var al = document.getElementById('orgLocationAddBtn');   if (al) al.addEventListener('click', addLocation);
});

/* ══════════ PROFILE ══════════ */
function renderProfile(p) {
    var fields = ['display_name','principal_name','established_year','affiliation_board',
                  'affiliation_no','address','city','state','pincode','phone','email','website'];
    fields.forEach(function(f) {
        var el = document.getElementById('pf_' + f);
        if (el) el.value = p[f] || '';
    });
    // District select must cascade off the state select value (setting .value alone won't populate it).
    if (window.IndiaGeo) {
        IndiaGeo.fillDistricts(document.getElementById('pf_state'), document.getElementById('pf_city'), p.city || '');
    }
    if (p.logo_url) {
        document.getElementById('logoImg').src = p.logo_url;
        document.getElementById('logoImg').style.display = 'block';
        document.getElementById('logoPlaceholder').style.display = 'none';
    }
    // Document links
    var hLink = document.getElementById('docHolidaysLink');
    var aLink = document.getElementById('docAcademicLink');
    if (p.holidays_calendar) { hLink.href = p.holidays_calendar; hLink.style.display = 'inline'; }
    else { hLink.style.display = 'none'; }
    if (p.academic_calendar) { aLink.href = p.academic_calendar; aLink.style.display = 'inline'; }
    else { aLink.style.display = 'none'; }
}

function saveProfile() {
    var data = {};
    var fields = ['display_name','principal_name','established_year','affiliation_board',
                  'affiliation_no','address','city','state','pincode','phone','email','website'];
    fields.forEach(function(f) {
        var el = document.getElementById('pf_' + f);
        if (el && el.value.trim()) data[f] = el.value.trim();
    });
    post('school_config/save_profile', data, function(d) {
        toast(d.message || (d.status === 'success' ? 'Saved!' : d.message), d.status === 'success');
        // Live-update the school name in the sidebar header (no reload needed).
        if (d.status === 'success' && d.display_name) {
            document.querySelectorAll('.g-school-name').forEach(function(el) {
                el.textContent = d.display_name.toUpperCase();
            });
        }
    }, { loader: 'Saving school details…' });
}

/* ══════════ PASSWORD RECOVERY CONTACT ══════════ */
function renderForgetPwd(f) {
    document.getElementById('fpd_name').value   = f.name   || '';
    document.getElementById('fpd_email').value  = f.email  || '';
    document.getElementById('fpd_number').value = f.number || '';
}

function saveForgetPwd() {
    var name   = document.getElementById('fpd_name').value.trim();
    var email  = document.getElementById('fpd_email').value.trim();
    var number = document.getElementById('fpd_number').value.trim();
    if (!name || !email || !number) { toast('Name, email and number are all required.', false); return; }
    if (!/^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email)) { toast('Enter a valid email address.', false); return; }
    if (!/^[\d\s\+\-\(\)]{7,20}$/.test(number)) { toast('Enter a valid phone number.', false); return; }
    post('school_config/save_forget_password_details', { name: name, email: email, number: number }, function(d) {
        toast(d.message || (d.status === 'success' ? 'Saved!' : d.message), d.status === 'success');
        if (d.status === 'success' && d.forget_password_details) renderForgetPwd(d.forget_password_details);
    }, { loader: 'Saving recovery contact…' });
}

/* Logo upload */
document.getElementById('logoFile').addEventListener('change', function() {
    if (!this.files.length) return;
    var fd = new FormData();
    fd.append('logo', this.files[0]);
    fd.append(CSRFN, CSRFT);
    document.getElementById('logoMsg').textContent = '';
    if (window.ZXLoader) ZXLoader.show('Uploading logo…');
    fetch(BASE + 'school_config/upload_logo', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRFT },
        body: fd,
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.status === 'success') {
            document.getElementById('logoImg').src = d.logo_url;
            document.getElementById('logoImg').style.display = 'block';
            document.getElementById('logoPlaceholder').style.display = 'none';
            document.getElementById('logoMsg').textContent = '';
            toast('Logo uploaded!');
        } else {
            document.getElementById('logoMsg').textContent = d.message;
            document.getElementById('logoMsg').style.color = '#c0392b';
            toast(d.message, false);
        }
    })
    .catch(function() { toast('Upload failed.', false); })
    .finally(function() { if (window.ZXLoader) ZXLoader.hide(); });
});

/* Document upload (Holidays / Academic Calendar) */
function uploadDoc(inputId, docType, msgId, linkId) {
    var input = document.getElementById(inputId);
    if (!input.files.length) return;
    var fd = new FormData();
    fd.append('document', input.files[0]);
    fd.append('doc_type', docType);
    fd.append(CSRFN, CSRFT);
    var msg = document.getElementById(msgId);
    msg.textContent = '';
    if (window.ZXLoader) ZXLoader.show('Uploading document…');
    fetch(BASE + 'school_config/upload_document', {
        method: 'POST',
        headers: { 'X-CSRF-Token': CSRFT },
        body: fd,
    })
    .then(function(r) { return r.json(); })
    .then(function(d) {
        if (d.csrf_token) CSRFT = d.csrf_token;
        if (d.status === 'success') {
            msg.textContent = '';
            var link = document.getElementById(linkId);
            link.href = d.url;
            link.style.display = 'inline';
            toast(d.message);
        } else {
            msg.textContent = d.message;
            msg.style.color = '#c0392b';
            toast(d.message, false);
        }
    })
    .catch(function() { msg.textContent = 'Upload failed.'; toast('Upload failed.', false); })
    .finally(function() { if (window.ZXLoader) ZXLoader.hide(); });
}
document.getElementById('docHolidays').addEventListener('change', function() {
    uploadDoc('docHolidays', 'holidays_calendar', 'docHolidaysMsg', 'docHolidaysLink');
});
document.getElementById('docAcademic').addEventListener('change', function() {
    uploadDoc('docAcademic', 'academic_calendar', 'docAcademicMsg', 'docAcademicLink');
});

/* ══════════ SESSIONS ══════════ */
var _sessStats = {}; // session -> {students, sections, classes, staff}

function _buildSessPill(s, active, archivedList) {
    var isActive   = s === active;
    var isArchived = (archivedList || []).indexOf(s) !== -1;
    var st = _sessStats[s] || {};
    var statsLine = st.students !== undefined
        ? ('<span style="font-size:10.5px;color:var(--t3);margin-left:6px;">'
            + '<i class="fa fa-users" style="margin-right:3px;"></i>' + (st.students || 0)
            + ' &nbsp;<i class="fa fa-th-large" style="margin-right:3px;"></i>' + (st.classes || 0) + 'c/' + (st.sections || 0) + 's'
            + ' &nbsp;<i class="fa fa-user-circle" style="margin-right:3px;"></i>' + (st.staff || 0)
            + '</span>')
        : '';
    var cls = 'sc-sess-pill';
    if (isActive)   cls += ' active-sess';
    if (isArchived) cls += ' archived-sess';

    var badge = '';
    if (isActive)       badge = '<span class="sc-sess-badge">ACTIVE</span>';
    else if (isArchived) badge = '<span class="sc-sess-badge">ARCHIVED</span>';

    var actions = '';
    if (!isActive) {
        actions += '<span class="sc-sess-set" onclick="setActive(\'' + esc(s) + '\')">Set Active</span>';
    }
    if (isActive) {
        actions += '<span class="sc-sess-action" style="opacity:.45;cursor:not-allowed;" title="Cannot archive the active session. Set a different session as active first.">'
                 + '<i class="fa fa-archive"></i> Archive</span>';
        actions += '<span class="sc-sess-action danger" style="opacity:.45;cursor:not-allowed;" title="Cannot delete the active session. Set a different session as active first.">'
                 + '<i class="fa fa-trash"></i> Delete</span>';
    } else if (isArchived) {
        actions += '<span class="sc-sess-action" title="Unarchive" onclick="archiveSession(\'' + esc(s) + '\', 0)"><i class="fa fa-archive"></i> Unarchive</span>';
        actions += '<span class="sc-sess-action danger" title="Delete (only if empty)" onclick="deleteSession(\'' + esc(s) + '\')"><i class="fa fa-trash"></i> Delete</span>';
    } else {
        actions += '<span class="sc-sess-action" title="Archive (hide from dropdowns, keep data)" onclick="archiveSession(\'' + esc(s) + '\', 1)"><i class="fa fa-archive"></i> Archive</span>';
        actions += '<span class="sc-sess-action danger" title="Delete (only if empty)" onclick="deleteSession(\'' + esc(s) + '\')"><i class="fa fa-trash"></i> Delete</span>';
    }

    return '<div class="' + cls + '">'
        + '<i class="fa fa-calendar-o" style="font-size:12px;"></i>'
        + '<span>' + esc(s) + '</span>'
        + statsLine
        + badge
        + actions
        + '</div>';
}

function renderSessions(sessions, active) {
    var el = document.getElementById('sessList');
    if (!sessions.length) { el.innerHTML = '<div class="sc-empty"><i class="fa fa-calendar-o"></i>No sessions yet.</div>'; return; }
    var archived = CFG.archived_sessions || [];
    el.innerHTML = sessions.map(function(s) { return _buildSessPill(s, active, archived); }).join('');
    loadSessionStats(sessions);
}

function loadSessionStats(sessions) {
    if (!sessions || !sessions.length) return;
    // Only fetch once per tab visit; cheap endpoint but avoid spamming.
    if (loadSessionStats._inflight) return;
    loadSessionStats._inflight = true;
    // Background enrichment — sessions are already on screen; don't block with the overlay.
    post('school_config/session_stats', {}, function(d) {
        loadSessionStats._inflight = false;
        if (d.status !== 'success' || !Array.isArray(d.stats)) return;
        _sessStats = {};
        d.stats.forEach(function(r) { _sessStats[r.session] = r; });
        // Re-render with stats now populated.
        renderSessionsOnly(CFG.sessions || sessions, CFG.active_session || '');
    }, { loader: false });
}

// Re-render without re-triggering stats fetch (avoids loop).
function renderSessionsOnly(sessions, active) {
    var el = document.getElementById('sessList');
    if (!el || !sessions.length) return;
    var archived = CFG.archived_sessions || [];
    el.innerHTML = sessions.map(function(s) { return _buildSessPill(s, active, archived); }).join('');
}

window.openRolloverModal = function() {
    var fromSel = document.getElementById('rollFrom');
    var sessions = (CFG.sessions || []).slice().sort();
    fromSel.innerHTML = sessions.map(function(s) {
        return '<option value="' + esc(s) + '"' + (s === CFG.active_session ? ' selected' : '') + '>' + esc(s) + '</option>';
    }).join('');
    // Suggest next-session in the "to" field
    var base = (CFG.active_session || sessions[sessions.length - 1] || '');
    var m = base && base.match(/^(\d{4})-(\d{2})$/);
    var next = '';
    if (m) {
        var startY = parseInt(m[1], 10) + 1;
        next = startY + '-' + String((startY + 1) % 100).padStart(2, '0');
    }
    document.getElementById('rollTo').value = next;
    document.getElementById('rollPreviewBox').style.display = 'none';
    document.getElementById('rollPromote').checked = false;
    var copyBox = document.getElementById('rollCopySections');
    copyBox.checked = true;
    copyBox.disabled = false;
    copyBox.title = '';
    document.getElementById('rollSetActive').checked = false;
    document.getElementById('rollPromoteWarn').style.display = 'none';
    document.getElementById('rolloverModal').style.display = 'flex';
};

window.closeRolloverModal = function() {
    document.getElementById('rolloverModal').style.display = 'none';
};

document.addEventListener('change', function(e) {
    if (e.target && e.target.id === 'rollPromote') {
        document.getElementById('rollPromoteWarn').style.display = e.target.checked ? 'block' : 'none';
        // Coupling: promotion requires target sections to exist — force copy_sections ON and lock it.
        var copyBox = document.getElementById('rollCopySections');
        if (e.target.checked) {
            copyBox.checked = true;
            copyBox.disabled = true;
            copyBox.title = 'Required when promoting students (target sections must exist).';
        } else {
            copyBox.disabled = false;
            copyBox.title = '';
        }
    }
    // Block unticking copy when promote is on.
    if (e.target && e.target.id === 'rollCopySections') {
        var promoteBox = document.getElementById('rollPromote');
        if (promoteBox.checked && !e.target.checked) {
            e.target.checked = true;
            toast('Copy sections is required while Promote students is enabled.', false);
        }
    }
});

window.rollPreview = function() {
    var from = document.getElementById('rollFrom').value;
    var to   = document.getElementById('rollTo').value.trim();
    if (!from || !to) { toast('Select both from and to sessions.', false); return; }
    if (!/^\d{4}-\d{2}$/.test(to)) { toast('Target session must be YYYY-YY format.', false); return; }
    post('school_config/preview_rollover', { from_session: from, to_session: to }, function(d) {
        if (d.status !== 'success') { toast(d.message || 'Preview failed.', false); return; }
        var box = document.getElementById('rollPreviewBox');
        var html = '<div style="margin-bottom:6px;"><b>Preview: ' + esc(from) + ' &rarr; ' + esc(to) + '</b>'
                 + (d.target_in_list ? '' : ' <span style="color:#d97706;">(target will be added to sessions list)</span>') + '</div>'
                 + '<ul style="margin:0 0 6px 18px;padding:0;">'
                 + '<li>' + d.sections_to_copy + ' section(s) in source</li>'
                 + '<li>' + d.active_students + ' active student(s) in source</li>'
                 + '<li>' + (d.graduating || 0) + ' student(s) in '
                 + (d.graduating_class || 'your highest class')
                 + ' (would complete schooling and become Alumni if promoted)</li>'
                 + '<li>Target already has: ' + d.existing_sections + ' section(s), ' + d.existing_students + ' student(s)</li>'
                 + '</ul>';
        if (d.warnings && d.warnings.length) {
            html += '<div style="color:#d97706;margin-top:4px;"><b>Warnings:</b><ul style="margin:4px 0 0 18px;padding:0;">'
                  + d.warnings.map(function(w) { return '<li>' + esc(w) + '</li>'; }).join('')
                  + '</ul></div>';
        }
        box.innerHTML = html;
        box.style.display = 'block';
    });
};

window.rollExecute = function() {
    var from = document.getElementById('rollFrom').value;
    var to   = document.getElementById('rollTo').value.trim();
    if (!from || !to) { toast('Select both from and to sessions.', false); return; }
    if (!/^\d{4}-\d{2}$/.test(to)) { toast('Target session must be YYYY-YY format.', false); return; }
    var copy = document.getElementById('rollCopySections').checked ? 1 : 0;
    var prom = document.getElementById('rollPromote').checked ? 1 : 0;
    var act  = document.getElementById('rollSetActive').checked ? 1 : 0;
    if (!copy && !prom) { toast('Select at least one action (copy sections or promote students).', false); return; }

    var warn = 'Execute rollover ' + from + ' \u2192 ' + to + '?\n\n';
    if (copy) warn += '  \u2022 Copy class/section structure\n';
    if (prom) warn += '  \u2022 PROMOTE students (Class N \u2192 N+1; your highest configured class \u2192 Alumni)\n';
    if (act)  warn += '  \u2022 Set ' + to + ' as the ACTIVE session\n';
    warn += '\nThis cannot be automatically reversed. Proceed?';
    _confirmModal({
        title: 'Execute rollover ' + from + ' → ' + to,
        body: warn,
        confirmText: 'Execute rollover',
        dangerous: true
    }).then(function(ok) {
        if (!ok) return;

        var btn = document.getElementById('rollExecBtn');
        btn.disabled = true;

        post('school_config/rollover_session', {
            from_session: from, to_session: to,
            copy_sections: copy, promote_students: prom, set_active: act,
        }, function(d) {
            btn.disabled = false;
            toast(d.message, d.status === 'success');
            if (d.status === 'success') {
                CFG.sessions = d.sessions || CFG.sessions;
                if (act && d.active_session) {
                    CFG.active_session = d.active_session;
                    currentSession = d.active_session;
                    var headerLabel = document.getElementById('gSessLabel');
                    if (headerLabel) headerLabel.textContent = d.active_session;
                }
                renderSessions(CFG.sessions, CFG.active_session || '');
                renderClassSelects(CFG.classes || [], CFG.sessions, CFG.active_session || '');
                // 2026-05-27 — rollover may have added a new target
                // session to sessions[] AND/OR flipped active_session.
                // Rebuild header dropdown so it carries the new year and
                // the active-class lands on the right <li>.
                refreshHeaderSessList(CFG.sessions || [], CFG.active_session || '');
                closeRolloverModal();
                if (d.summary && d.summary.errors && d.summary.errors.length) {
                    // BUG-066 Wave 1: removed slice(0,5) truncation; modal body scrolls overflow.
                    _confirmModal({
                        title: 'Rollover finished with ' + d.summary.errors.length + ' error(s)',
                        body: d.summary.errors.join('\n'),
                        alertOnly: true
                    });
                }
            }
        });
    });
};

/* ────────────────────────────────────────────────────────────────
 * R1.4 — Step 2: Fees rollover (freeze + carry-forward)
 *
 * Independent of Step 1. Hits Fees::year_rollover_prepare for
 * read-only preview and Fees::year_rollover_execute for the
 * irreversible freeze + per-student carry-forward write.
 *
 * Type-to-confirm: admin must type the target session year EXACTLY
 * before the execute call fires. This is the single most destructive
 * Fees-domain operation in the system; an accidental click should
 * never trigger it.
 * ──────────────────────────────────────────────────────────────── */

window.rollFeesPreview = function() {
    var from = document.getElementById('rollFrom').value;
    var to   = document.getElementById('rollTo').value.trim();
    if (!from || !to) { toast('Select both From and To sessions above.', false); return; }
    if (!/^\d{4}-\d{2}$/.test(to)) { toast('Target session must be YYYY-YY format.', false); return; }
    if (from === to) { toast('From and To sessions must differ.', false); return; }

    post('fees/year_rollover_prepare', { new_session: to }, function(d) {
        if (d.status !== 'success') { toast(d.message || 'Preview failed.', false); return; }
        var box = document.getElementById('rollFeesPreviewBox');
        var execBtn = document.getElementById('rollFeesExecBtn');
        var html = '';

        // Headline counts.
        html += '<div style="margin-bottom:8px;"><b>Preview: ' + esc(from) + ' &rarr; ' + esc(to) + '</b></div>';
        html += '<div style="display:grid;grid-template-columns:1fr 1fr;gap:6px;margin-bottom:8px;">'
              + '<div><b>' + (d.carry_forward_count || 0) + '</b> students with dues</div>'
              + '<div>Total: <b>₹ ' + Number(d.total_carry_forward || 0).toLocaleString("en-IN") + '</b></div>'
              + '</div>';

        // Blockers — must be empty before execute is allowed.
        var blockers = [];
        if (d.in_flight_count > 0)   blockers.push(d.in_flight_count + ' in-flight payment lock(s)');
        if (d.open_orders_count > 0) blockers.push(d.open_orders_count + ' unverified Razorpay order(s)');
        if (blockers.length) {
            html += '<div style="background:rgba(220,38,38,.10);border:1px solid rgba(220,38,38,.4);border-radius:6px;padding:8px;margin-bottom:6px;color:#991b1b;">'
                  + '<b>Blocked:</b> ' + esc(blockers.join(', '))
                  + '<div style="font-size:11px;margin-top:4px;">Wait for these to clear before executing.</div>'
                  + '</div>';
        }

        // Warnings — non-blocking but worth surfacing.
        if (d.already_frozen) {
            html += '<div style="background:rgba(217,119,6,.10);border:1px solid rgba(217,119,6,.4);border-radius:6px;padding:8px;margin-bottom:6px;color:#92400e;">'
                  + '<b>Already frozen:</b> ' + esc(from) + ' was rolled over on ' + esc(d.frozen_at || 'unknown') + ' by ' + esc(d.frozen_by || 'unknown') + '. Re-running within 24h is blocked unless you pass force=1.'
                  + '</div>';
        }
        if (d.target_warning) {
            html += '<div style="background:rgba(217,119,6,.10);border:1px solid rgba(217,119,6,.4);border-radius:6px;padding:8px;margin-bottom:6px;color:#92400e;">'
                  + esc(d.target_warning)
                  + '</div>';
        }

        // Top dues — anomaly spot-check.
        if (Array.isArray(d.top_dues) && d.top_dues.length) {
            html += '<div style="margin-top:8px;"><b>Top dues:</b><ul style="margin:4px 0 0 18px;padding:0;">';
            d.top_dues.slice(0, 5).forEach(function(t) {
                html += '<li>' + esc(t.student_name || t.student_id) + ' &mdash; ' + esc(t.class || '') + ' ' + esc(t.section || '')
                      + ' &mdash; <b>₹ ' + Number(t.dues).toLocaleString("en-IN") + '</b></li>';
            });
            html += '</ul></div>';
        }

        // Per-class breakdown — sanity check distribution.
        if (Array.isArray(d.per_class_breakdown) && d.per_class_breakdown.length) {
            html += '<div style="margin-top:8px;"><b>By class:</b> '
                  + d.per_class_breakdown.map(function(c) {
                        return esc(c.class) + ' (' + c.count + ' &middot; ₹ ' + Number(c.dues).toLocaleString("en-IN") + ')';
                    }).join(', ')
                  + '</div>';
        }

        box.innerHTML = html;
        box.style.display = 'block';

        // Execute button gating.
        if (d.can_proceed && !d.already_frozen) {
            execBtn.style.display = 'inline-flex';
            execBtn.disabled = false;
        } else {
            execBtn.style.display = 'none';
        }

        // Cache the preview snapshot for the type-to-confirm dialog.
        window._feesRollPreview = d;
    });
};

window.rollFeesExecute = function() {
    var from = document.getElementById('rollFrom').value;
    var to   = document.getElementById('rollTo').value.trim();
    if (!from || !to) { toast('Select both From and To sessions above.', false); return; }
    if (!/^\d{4}-\d{2}$/.test(to)) { toast('Target session must be YYYY-YY format.', false); return; }

    var snap = window._feesRollPreview || {};
    var summary = (snap.carry_forward_count || 0) + ' students &middot; ₹ '
                + Number(snap.total_carry_forward || 0).toLocaleString("en-IN");

    _typeToConfirmDialog({
        title: 'Freeze ' + from + ' and carry forward dues',
        body: 'You are about to:\n\n' +
              '  • Mark ' + from + ' as FROZEN (no new payments accepted)\n' +
              '  • Write ' + (snap.carry_forward_count || 0) + ' carry-forward record(s) into ' + to + '\n' +
              '  • Total amount carried forward: ₹ ' + Number(snap.total_carry_forward || 0).toLocaleString("en-IN") + '\n\n' +
              'This action is irreversible from the UI. Type the target session below to confirm.',
        expectedToken: to,
        confirmText: 'Freeze and carry forward',
        dangerous: true
    }).then(function(ok) {
        if (!ok) return;
        var btn = document.getElementById('rollFeesExecBtn');
        btn.disabled = true;
        post('fees/year_rollover_execute', { new_session: to }, function(d) {
            btn.disabled = false;
            if (d.status !== 'success') {
                toast(d.message || 'Rollover failed.', false);
                return;
            }
            toast('Rollover complete: ' + (d.message || ''), true);
            // Refresh preview so the admin sees the new "frozen" state.
            rollFeesPreview();
        });
    });
};

/**
 * Type-to-confirm dialog for irreversible operations. Admin must type
 * `expectedToken` (here: the target session year) verbatim before the
 * Confirm button enables. Esc cancels. Backdrop click does NOT dismiss
 * — operation is too destructive for accidental dismissal.
 *
 * Returns Promise<boolean>.
 */
function _typeToConfirmDialog(opts) {
    return new Promise(function(resolve) {
        var prevFocus = document.activeElement;
        var bd = document.createElement('div');
        bd.setAttribute('role', 'dialog');
        bd.setAttribute('aria-modal', 'true');
        bd.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:11000;display:flex;align-items:center;justify-content:center;padding:16px;';

        var card = document.createElement('div');
        card.style.cssText = 'background:#fff;border-radius:14px;max-width:540px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);';

        var hdr = document.createElement('div');
        hdr.style.cssText = 'padding:14px 18px;border-bottom:1px solid #e5e7eb;background:' + (opts.dangerous ? '#fef2f2' : '#f8fafc') + ';display:flex;align-items:center;gap:10px;';
        var icon = document.createElement('i');
        icon.className = 'fa ' + (opts.dangerous ? 'fa-exclamation-triangle' : 'fa-question-circle');
        icon.style.cssText = 'font-size:20px;color:' + (opts.dangerous ? '#dc2626' : '#BC5A3C') + ';';
        hdr.appendChild(icon);
        var title = document.createElement('h4');
        title.style.cssText = 'margin:0;font-size:15px;font-weight:600;color:#0f172a;';
        title.textContent = opts.title || 'Please confirm';
        hdr.appendChild(title);

        var body = document.createElement('div');
        body.style.cssText = 'padding:18px;color:#334155;font-size:13.5px;line-height:1.55;white-space:pre-wrap;';
        body.textContent = opts.body || '';

        var inputWrap = document.createElement('div');
        inputWrap.style.cssText = 'padding:0 18px 16px;';
        var inputLabel = document.createElement('label');
        inputLabel.textContent = 'Type "' + (opts.expectedToken || '') + '" to confirm:';
        inputLabel.style.cssText = 'display:block;font-size:12px;color:#64748b;margin-bottom:4px;';
        var input = document.createElement('input');
        input.type = 'text';
        input.style.cssText = 'width:100%;padding:8px 10px;border:1px solid #cbd5e1;border-radius:6px;font-size:14px;font-family:inherit;';
        inputWrap.appendChild(inputLabel);
        inputWrap.appendChild(input);

        var ftr = document.createElement('div');
        ftr.style.cssText = 'padding:12px 16px;border-top:1px solid #e5e7eb;background:#f8fafc;display:flex;gap:8px;justify-content:flex-end;';
        var btnCancel = document.createElement('button');
        btnCancel.type = 'button';
        btnCancel.textContent = 'Cancel';
        btnCancel.style.cssText = 'padding:8px 16px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;color:#475569;font-weight:500;cursor:pointer;';
        var btnConfirm = document.createElement('button');
        btnConfirm.type = 'button';
        btnConfirm.textContent = opts.confirmText || 'Confirm';
        btnConfirm.disabled = true;
        btnConfirm.style.cssText = 'padding:8px 16px;border-radius:8px;border:none;color:#fff;font-weight:600;cursor:pointer;'
            + 'background:' + (opts.dangerous ? '#dc2626' : '#BC5A3C') + ';opacity:.5;';
        ftr.appendChild(btnCancel);
        ftr.appendChild(btnConfirm);

        card.appendChild(hdr);
        card.appendChild(body);
        card.appendChild(inputWrap);
        card.appendChild(ftr);
        bd.appendChild(card);
        document.body.appendChild(bd);

        function cleanup() {
            document.removeEventListener('keydown', onKey, true);
            if (bd.parentNode) bd.parentNode.removeChild(bd);
            if (prevFocus && typeof prevFocus.focus === 'function') {
                try { prevFocus.focus(); } catch (_) {}
            }
        }
        function done(v) { cleanup(); resolve(v); }

        function onKey(e) {
            if (e.key === 'Escape') { e.preventDefault(); done(false); return; }
            if (e.key === 'Tab') {
                // 3-element trap: input, cancel, confirm.
                var seq = btnConfirm.disabled ? [input, btnCancel] : [input, btnCancel, btnConfirm];
                var idx = seq.indexOf(document.activeElement);
                if (idx === -1) return;
                e.preventDefault();
                var nextIdx = e.shiftKey ? (idx - 1 + seq.length) % seq.length : (idx + 1) % seq.length;
                seq[nextIdx].focus();
            }
        }
        document.addEventListener('keydown', onKey, true);

        input.addEventListener('input', function() {
            var match = input.value === (opts.expectedToken || '');
            btnConfirm.disabled = !match;
            btnConfirm.style.opacity = match ? '1' : '.5';
        });
        btnCancel.addEventListener('click', function() { done(false); });
        btnConfirm.addEventListener('click', function() {
            if (input.value === (opts.expectedToken || '')) done(true);
        });

        // Focus the input so admin can start typing immediately.
        setTimeout(function() { input.focus(); }, 0);
    });
}

/**
 * 2026-05-26 (BUG-066 Wave 1) — Simpler confirm / info modal. Sibling of
 * _typeToConfirmDialog for non-irreversible flows: a clean Yes/No dialog
 * with consistent styling, ARIA modal semantics, Esc-to-cancel, Tab trap,
 * Enter-to-confirm. opts:
 *   - title:       string
 *   - body:        string (newlines preserved via white-space:pre-wrap)
 *   - confirmText: string (default 'Confirm')
 *   - cancelText:  string (default 'Cancel')
 *   - dangerous:   bool   (red header + red Confirm button)
 *   - alertOnly:   bool   (hide Cancel; relabel Confirm 'OK'; Esc resolves true)
 * Returns Promise<boolean>.
 */
function _confirmModal(opts) {
    opts = opts || {};
    return new Promise(function(resolve) {
        var prevFocus = document.activeElement;
        var bd = document.createElement('div');
        bd.setAttribute('role', 'dialog');
        bd.setAttribute('aria-modal', 'true');
        bd.style.cssText = 'position:fixed;inset:0;background:rgba(15,23,42,.6);z-index:11000;display:flex;align-items:center;justify-content:center;padding:16px;';

        var card = document.createElement('div');
        card.style.cssText = 'background:#fff;border-radius:14px;max-width:540px;width:100%;box-shadow:0 20px 60px rgba(0,0,0,.3);';

        var hdr = document.createElement('div');
        hdr.style.cssText = 'padding:14px 18px;border-bottom:1px solid #e5e7eb;background:' + (opts.dangerous ? '#fef2f2' : '#f8fafc') + ';display:flex;align-items:center;gap:10px;';
        var icon = document.createElement('i');
        icon.className = 'fa ' + (opts.dangerous ? 'fa-exclamation-triangle' : 'fa-info-circle');
        icon.style.cssText = 'font-size:20px;color:' + (opts.dangerous ? '#dc2626' : '#BC5A3C') + ';';
        hdr.appendChild(icon);
        var title = document.createElement('h4');
        title.style.cssText = 'margin:0;font-size:15px;font-weight:600;color:#0f172a;';
        title.textContent = opts.title || 'Please confirm';
        hdr.appendChild(title);

        var body = document.createElement('div');
        body.style.cssText = 'padding:18px;color:#334155;font-size:13.5px;line-height:1.55;white-space:pre-wrap;max-height:60vh;overflow-y:auto;';
        body.textContent = opts.body || '';

        var ftr = document.createElement('div');
        ftr.style.cssText = 'padding:12px 16px;border-top:1px solid #e5e7eb;background:#f8fafc;display:flex;gap:8px;justify-content:flex-end;';

        var btnCancel = null;
        if (!opts.alertOnly) {
            btnCancel = document.createElement('button');
            btnCancel.type = 'button';
            btnCancel.textContent = opts.cancelText || 'Cancel';
            btnCancel.style.cssText = 'padding:8px 16px;border-radius:8px;border:1px solid #cbd5e1;background:#fff;color:#475569;font-weight:500;cursor:pointer;';
            ftr.appendChild(btnCancel);
        }
        var btnConfirm = document.createElement('button');
        btnConfirm.type = 'button';
        btnConfirm.textContent = opts.alertOnly ? 'OK' : (opts.confirmText || 'Confirm');
        btnConfirm.style.cssText = 'padding:8px 16px;border-radius:8px;border:none;color:#fff;font-weight:600;cursor:pointer;'
            + 'background:' + (opts.dangerous ? '#dc2626' : '#BC5A3C') + ';';
        ftr.appendChild(btnConfirm);

        card.appendChild(hdr);
        card.appendChild(body);
        card.appendChild(ftr);
        bd.appendChild(card);
        document.body.appendChild(bd);

        function cleanup() {
            document.removeEventListener('keydown', onKey, true);
            if (bd.parentNode) bd.parentNode.removeChild(bd);
            if (prevFocus && typeof prevFocus.focus === 'function') {
                try { prevFocus.focus(); } catch (_) {}
            }
        }
        function done(v) { cleanup(); resolve(v); }

        function onKey(e) {
            if (e.key === 'Escape') {
                e.preventDefault();
                done(opts.alertOnly ? true : false);
                return;
            }
            if (e.key === 'Tab') {
                var seq = btnCancel ? [btnCancel, btnConfirm] : [btnConfirm];
                var idx = seq.indexOf(document.activeElement);
                if (idx === -1) { e.preventDefault(); seq[0].focus(); return; }
                e.preventDefault();
                var nextIdx = e.shiftKey ? (idx - 1 + seq.length) % seq.length : (idx + 1) % seq.length;
                seq[nextIdx].focus();
            }
            if (e.key === 'Enter' && document.activeElement === btnConfirm) {
                e.preventDefault();
                done(true);
            }
        }
        document.addEventListener('keydown', onKey, true);

        if (btnCancel) btnCancel.addEventListener('click', function() { done(false); });
        btnConfirm.addEventListener('click', function() { done(true); });

        setTimeout(function() { btnConfirm.focus(); }, 0);
    });
}

/* ────────────────────────────────────────────────────────────────
 * 2026-05-27 — refreshHeaderSessList(sessions, active)
 *
 * Rebuilds the global header session-switcher dropdown
 * (`<ul.g-sess-list>`) from a fresh sessions array. Used by every
 * session-mutation handler on this page so the header dropdown
 * stays synchronized with both the Session-tab list AND the
 * server-side `available_sessions` PHP userdata after AJAX calls
 * (which previously left the dropdown DOM stale until full page
 * reload — see "Sync from Firebase" forensic 2026-05-27).
 *
 * Contract preservation:
 *   1. Click handler on each <li> mirrors the server-rendered
 *      header.php logic at lines 1391-1404 exactly: short-circuit
 *      on active item, POST to admin/switch_session, reload on
 *      success. This is intentional duplication; the source-of-
 *      truth handler lives in header.php for server-rendered
 *      items and the same shape is rebuilt here for dynamically-
 *      replaced items.
 *   2. The active item carries class `g-sess-item--active`
 *      (CSS at header.php:519) and renders the check icon visible.
 *   3. data-year attribute drives both the click handler and the
 *      "latest session" computation in the New Session modal at
 *      header.php:1411-1418, so we preserve it verbatim.
 * ──────────────────────────────────────────────────────────────── */
function refreshHeaderSessList(sessions, active) {
    var headerList = document.querySelector('.g-sess-list');
    if (!headerList) return;   // header dropdown not on this page — no-op
    headerList.innerHTML = '';
    (sessions || []).forEach(function(yr) {
        var isActive = (yr === active);
        var li = document.createElement('li');
        li.className = 'g-sess-item' + (isActive ? ' g-sess-item--active' : '');
        li.dataset.year = yr;
        li.innerHTML = '<i class="fa fa-check g-sess-check"></i> '
                     + yr.replace(/[<>&"']/g, function(c) {
                         return {'<':'&lt;','>':'&gt;','&':'&amp;','"':'&quot;',"'":'&#39;'}[c];
                       });
        li.addEventListener('click', function() {
            var year = this.dataset.year;
            if (this.classList.contains('g-sess-item--active')) {
                var panel = document.getElementById('gSessPanel');
                if (panel) panel.classList.remove('open');
                return;
            }
            // SC-Step6 (2026-06-02): repointed from admin/switch_session
            // (retired) to canonical school_config/set_active_session.
            // Body param renamed session_year → session per endpoint contract.
            $.post(BASE_URL + 'school_config/set_active_session', { session: year })
             .done(function(res) {
                 if (res && res.status === 'success') window.location.reload();
             })
             .fail(function() {
                 _confirmModal({
                     title: 'Failed to switch session',
                     body: 'The server did not respond. Please reload the page and try again.',
                     alertOnly: true,
                     dangerous: true
                 });
             });
        });
        headerList.appendChild(li);
    });
}

window.archiveSession = function(sess, doArchive) {
    var verb = doArchive ? 'archive' : 'unarchive';
    function doIt() {
        post('school_config/archive_session', { session: sess, archive: doArchive ? 1 : 0 }, function(d) {
            toast(d.message, d.status === 'success');
            if (d.status === 'success') {
                CFG.archived_sessions = d.archived_sessions || [];
                renderSessionsOnly(CFG.sessions || [], CFG.active_session || '');
            }
        });
    }
    // Unarchive bypasses confirm; archive requires confirmation per original semantics.
    if (!doArchive) { doIt(); return; }
    _confirmModal({
        title: 'Archive "' + sess + '"',
        body: 'It will be hidden from default dropdowns but all data (students, marks, fees, attendance) is preserved. You can unarchive later.',
        confirmText: 'Archive'
    }).then(function(ok) {
        if (!ok) return;
        doIt();
    });
};

window.deleteSession = function(sess) {
    var st = _sessStats[sess] || {};
    if (st.students || st.sections || st.staff) {
        _confirmModal({
            title: 'Cannot delete "' + sess + '"',
            body: 'It still has:\n'
                + '  • ' + (st.students || 0) + ' students\n'
                + '  • ' + (st.classes  || 0) + ' classes / ' + (st.sections || 0) + ' sections\n'
                + '  • ' + (st.staff    || 0) + ' staff\n\n'
                + 'Delete or move that data first, or use Archive to keep it but hide the session.',
            alertOnly: true
        });
        return;
    }
    _confirmModal({
        title: 'Delete session "' + sess + '" permanently',
        body: 'This only removes it from the sessions list. The server will also refuse if any data is still linked to this session.',
        confirmText: 'Delete',
        dangerous: true
    }).then(function(ok) {
        if (!ok) return;
        post('school_config/delete_session', { session: sess }, function(d) {
            toast(d.message, d.status === 'success');
            if (d.status === 'success') {
                CFG.sessions = d.sessions || [];
                renderSessions(CFG.sessions, CFG.active_session || '');
                renderClassSelects(CFG.classes || [], CFG.sessions, CFG.active_session || '');
                // 2026-05-27 — drop the deleted session from the header
                // dropdown DOM. Without this call the deleted year stays
                // selectable in the header until full page reload,
                // landing the user on a 4xx from admin/switch_session.
                refreshHeaderSessList(CFG.sessions, CFG.active_session || '');
            }
        });
    });
};

window.suggestNextSession = function() {
    var list = (CFG.sessions || []).slice().sort();
    var base = list.length ? list[list.length - 1] : (CFG.active_session || '');
    var m = base && base.match(/^(\d{4})-(\d{2})$/);
    if (!m) {
        var y = new Date().getFullYear();
        // Indian academic year flips in April; if before April, use prev year as start.
        if ((new Date().getMonth() + 1) < 4) y -= 1;
        base = y + '-' + String((y + 1) % 100).padStart(2, '0');
        document.getElementById('newSessInput').value = base;
        toast('Suggested: ' + base);
        return;
    }
    var startY = parseInt(m[1], 10) + 1;
    var endYY  = String((startY + 1) % 100).padStart(2, '0');
    var next   = startY + '-' + endYY;
    document.getElementById('newSessInput').value = next;
    toast('Suggested: ' + next + '. Click "Add Session" to save.');
};

window.checkSessions = function() {
    var btn = document.getElementById('checkSessBtn');
    if (btn) { btn.disabled = true; }
    var box = document.getElementById('sessCheckResult');
    post('school_config/check_sessions', {}, function(d) {
        if (btn) { btn.disabled = false; }
        if (d.status !== 'success') { toast(d.message || 'Check failed.', false); return; }
        var healthy = d.healthy;
        var bg   = healthy ? 'rgba(16,185,129,.10)' : 'rgba(217,119,6,.10)';
        var brd  = healthy ? '1px solid rgba(16,185,129,.35)' : '1px solid rgba(217,119,6,.45)';
        var icon = healthy ? 'fa-check-circle' : 'fa-exclamation-triangle';
        var col  = healthy ? '#059669' : '#d97706';
        var body = '<div style="display:flex;align-items:center;gap:8px;color:' + col + ';font-weight:600;margin-bottom:' + ((d.issues && d.issues.length) ? '6px' : '0') + ';">'
                 + '<i class="fa ' + icon + '"></i> ' + esc(d.message || '') + '</div>';
        if (d.issues && d.issues.length) {
            body += '<ul style="margin:4px 0 0 18px;padding:0;color:var(--t2);">'
                  + d.issues.map(function(i) { return '<li>' + esc(i) + '</li>'; }).join('')
                  + '</ul>';
        }
        if (d.orphans && d.orphans.length) {
            body += '<div style="margin-top:6px;"><b>Tip:</b> Click "Sync from Firebase" to pull the orphaned sessions into the list.</div>';
        }
        box.style.cssText = 'display:block;margin:8px 0 4px;padding:10px 12px;border-radius:8px;font-size:12px;background:' + bg + ';border:' + brd + ';';
        box.innerHTML = body;
    });
};

// `silent` (passed when triggered by a Sessions-tab switch) does a background
// refresh with no full-screen overlay and no success toast — the list is
// already on screen from the initial load, so switching tabs shouldn't block
// the page. An explicit "Sync from Firebase" button click shows the overlay.
window.syncSessions = function(silent) {
    var btn = document.getElementById('syncSessBtn');
    if (btn) { btn.disabled = true; }

    post('school_config/sync_sessions', {}, function(d) {
        if (btn) { btn.disabled = false; }

        if (d.status === 'success') {
            CFG.sessions = d.sessions || [];
            if (d.active_session) CFG.active_session = d.active_session;
            if (d.archived_sessions) CFG.archived_sessions = d.archived_sessions;
            var act = CFG.active_session || d.active_session || '';
            renderSessions(d.sessions || [], act);
            renderClassSelects(CFG.classes || [], d.sessions || [], act);
            // 2026-05-27 — keep header dropdown in sync with the freshly-
            // pulled Firestore sessions array. Without this call the
            // dropdown retains any stale <li>s (e.g. a session that was
            // deleted in Firebase Console) until full page reload.
            refreshHeaderSessList(d.sessions || [], act);
            if (!silent) toast(d.message || 'Sessions refreshed from Firebase.');
        } else if (!silent) {
            toast(d.message || 'Sync failed.', false);
        }
    }, silent ? { loader: false } : { loader: 'Syncing sessions…' });
};

window.addSession = function() {
    var val = document.getElementById('newSessInput').value.trim();
    if (!val) { toast('Enter a session year.', false); return; }
    post('school_config/add_session', { session: val }, function(d) {
        toast(d.message, d.status === 'success');
        if (d.status === 'success') {
            document.getElementById('newSessInput').value = '';
            renderSessions(d.sessions, CFG.active_session || '');
            CFG.sessions = d.sessions;
            renderClassSelects(CFG.classes || [], d.sessions, CFG.active_session || '');
            // 2026-05-27 — replaces the previous inline DOM-append of a
            // single <li> with a full rebuild. Keeps the dropdown in
            // canonical order (matches server-rendered output exactly)
            // and shares the click-handler logic with refreshHeaderSessList.
            refreshHeaderSessList(d.sessions || [], CFG.active_session || '');
        }
    });
};

window.setActive = function(sess) {
    var st = _sessStats[sess] || {};
    var warn = 'Set "' + sess + '" as the ACTIVE session?\n\n'
             + 'This will immediately change the default session for:\n'
             + '  • All dashboards and reports\n'
             + '  • Student / staff / attendance lists\n'
             + '  • Fee vouchers and receipts\n'
             + '  • Exams, results, and report cards\n\n';
    if (st.students !== undefined) {
        warn += 'This session currently has:\n'
              + '  • ' + (st.students || 0) + ' students\n'
              + '  • ' + (st.classes  || 0) + ' classes / ' + (st.sections || 0) + ' sections\n'
              + '  • ' + (st.staff    || 0) + ' staff\n\n';
        if ((st.students || 0) === 0 && (st.sections || 0) === 0) {
            warn += 'WARNING: This session appears EMPTY. Activating it will show blank lists across the app until you add classes/students.\n\n';
        }
    }
    warn += 'Proceed?';
    _confirmModal({
        title: 'Set "' + sess + '" as ACTIVE session',
        body: warn,
        confirmText: 'Set active',
        dangerous: true
    }).then(function(ok) {
        if (!ok) return;
        post('school_config/set_active_session', { session: sess }, function(d) {
            toast(d.message, d.status === 'success');
            if (d.status === 'success') {
                CFG.active_session = sess;
                currentSession = sess;
                renderSessions(CFG.sessions || [], sess);
                renderClassSelects(CFG.classes || [], CFG.sessions || [], sess);

                // Sync the header session switcher without a full page reload
                var headerLabel = document.getElementById('gSessLabel');
                if (headerLabel) headerLabel.textContent = sess;
                // 2026-05-27 — full rebuild instead of class-only toggle.
                // Active-class update is sufficient when the sessions
                // list is unchanged, but the helper also re-binds click
                // handlers for any DOM that was patched by other flows
                // (deleteSession / syncSessions) since page load, so a
                // single source of truth here removes per-handler skew.
                refreshHeaderSessList(CFG.sessions || [], sess);
            }
        });
    });
};

/* ══════════ BOARD ══════════ */
function renderBoard(b) {
    if (b.type) {
        document.querySelectorAll('input[name="board_type"]').forEach(function(r) {
            r.checked = (r.value === b.type);
        });
        syncRadioGroup('board_type');
        // Show custom board name input for State, IB, Custom
        if (b.type === 'State' || b.type === 'IB' || b.type === 'Custom') {
            document.getElementById('customBoardRow').style.display = 'block';
        }
    }
    // Support both old field name (state_board_name) and new (custom_board_name)
    if (b.custom_board_name || b.state_board_name) {
        document.getElementById('customBoardName').value = b.custom_board_name || b.state_board_name || '';
    }
    if (b.grading_pattern) {
        document.querySelectorAll('input[name="grading_pattern"]').forEach(function(r) {
            r.checked = (r.value === b.grading_pattern);
        });
        syncRadioGroup('grading_pattern');
        if (b.grading_pattern !== 'marks') {
            document.getElementById('gradeScaleCard').style.display = 'block';
        }
    }
    if (b.passing_marks != null) document.getElementById('passingMarks').value = b.passing_marks;
    if (b.grade_scale && Array.isArray(b.grade_scale)) {
        b.grade_scale.forEach(function(row) { addGradeRow(row.grade, row.min_pct, row.max_pct); });
    }
}

function syncRadioGroup(name) {
    document.querySelectorAll('input[name="' + name + '"]').forEach(function(r) {
        r.closest('.sc-radio-opt').classList.toggle('sc-checked', r.checked);
    });
}

document.querySelectorAll('.sc-radio-group input[type=radio]').forEach(function(r) {
    r.addEventListener('change', function() {
        syncRadioGroup(this.name);
        if (this.name === 'board_type') {
            // Show custom name for State, IB, Custom
            var showCustom = (this.value === 'State' || this.value === 'IB' || this.value === 'Custom');
            document.getElementById('customBoardRow').style.display = showCustom ? 'block' : 'none';
        }
        if (this.name === 'grading_pattern') {
            document.getElementById('gradeScaleCard').style.display =
                this.value !== 'marks' ? 'block' : 'none';
        }
    });
});

window.addGradeRow = function(grade, min, max) {
    var wrap = document.getElementById('gradeScaleRows');
    var row  = document.createElement('div');
    row.className = 'sc-gs-row';
    row.innerHTML =
        '<div class="sc-field"><label>Grade</label>'
        + '<input type="text" class="gs-grade" value="' + (grade || '') + '" placeholder="A+" maxlength="5"></div>'
        + '<div class="sc-field"><label>Min %</label>'
        + '<input type="number" class="gs-min" value="' + (min != null ? min : '') + '" placeholder="90" min="0" max="100"></div>'
        + '<div class="sc-field"><label>Max %</label>'
        + '<input type="number" class="gs-max" value="' + (max != null ? max : '') + '" placeholder="100" min="0" max="100"></div>'
        + '<button class="sc-btn sc-btn-danger sc-btn-sm" style="margin-top:17px;" onclick="this.parentElement.remove()">'
        + '<i class="fa fa-trash"></i></button>';
    wrap.appendChild(row);
};

window.saveBoard = function() {
    var type = (document.querySelector('input[name="board_type"]:checked') || {}).value || '';
    var gp   = (document.querySelector('input[name="grading_pattern"]:checked') || {}).value || '';
    if (!type || !gp) { toast('Select board type and grading pattern.', false); return; }

    var gradeScale = [];
    document.querySelectorAll('#gradeScaleRows .sc-gs-row').forEach(function(row) {
        gradeScale.push({
            grade:   row.querySelector('.gs-grade').value.trim(),
            min_pct: parseFloat(row.querySelector('.gs-min').value) || 0,
            max_pct: parseFloat(row.querySelector('.gs-max').value) || 100,
        });
    });

    post('school_config/save_board', {
        type:              type,
        custom_board_name: document.getElementById('customBoardName').value.trim(),
        grading_pattern:   gp,
        passing_marks:     document.getElementById('passingMarks').value,
        grade_scale:       JSON.stringify(gradeScale),
    }, function(d) {
        toast(d.message || (d.status === 'success' ? 'Saved!' : d.message), d.status === 'success');
    });
};

/* ══════════ CLASSES ══════════ */
var showDeleted = false;

/* ── ADM-AGE-1 · age-policy editor ──────────────────────────────────────── */
var AGE_POLICY = {};

/* Bands are rendered FROM the class list, so a school cannot band a class it
   does not run, and a newly added class appears here the moment it is saved. */
function renderAgePolicy(classes, policy) {
    AGE_POLICY = policy || {};
    var modeEl = document.getElementById('agePolicyMode');
    var cutEl  = document.getElementById('agePolicyCutoff');
    if (!modeEl || !cutEl) return;

    modeEl.value = AGE_POLICY.mode || 'off';
    cutEl.value  = AGE_POLICY.cutoff || '03-31';

    var bands = AGE_POLICY.bands || {};
    var live  = (classes || []).filter(function (c) { return !c.deleted; });
    var wrap  = document.getElementById('agePolicyBands');
    if (!live.length) {
        wrap.innerHTML = '<div class="sc-empty">Define your classes above first — age bands are set per class.</div>';
        return;
    }

    wrap.innerHTML =
        '<div style="display:grid;grid-template-columns:1fr 90px 90px;gap:8px;'
      + 'font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;'
      + 'letter-spacing:.05em;padding:0 2px 6px;">'
      +   '<div>Class</div><div>Min age</div><div>Max age</div>'
      + '</div>'
      + live.map(function (c) {
            var nm = c.label || c.key || '';
            var b  = bands[nm] || bands[String(nm).replace('Class ', '')] || {};
            var v  = function (x) { return (x === null || x === undefined) ? '' : x; };
            return '<div style="display:grid;grid-template-columns:1fr 90px 90px;gap:8px;'
                 + 'align-items:center;padding:3px 2px;">'
                 +   '<div style="font-size:12.5px;">' + esc(nm) + '</div>'
                 +   '<input type="number" class="sc-input sc-age-min" data-class="' + esc(nm) + '" '
                 +     'min="0" max="30" value="' + esc(v(b.min)) + '" placeholder="—">'
                 +   '<input type="number" class="sc-input sc-age-max" data-class="' + esc(nm) + '" '
                 +     'min="0" max="30" value="' + esc(v(b.max)) + '" placeholder="—">'
                 + '</div>';
        }).join('');
}

function saveAgePolicy() {
    // post() urlencodes a FLAT map, and PHP parses bands[Class 1][min] out of that
    // into a nested array — so no FormData is needed and the CSRF handling in
    // post() is reused rather than reimplemented.
    var payload = {
        mode:   document.getElementById('agePolicyMode').value,
        cutoff: document.getElementById('agePolicyCutoff').value.trim()
    };

    // Only classes with at least one bound are sent; the server treats an absent
    // class as unbanded rather than as zero, which would refuse every admission.
    document.querySelectorAll('.sc-age-min').forEach(function (el) {
        var cls = el.getAttribute('data-class');
        var max = document.querySelector('.sc-age-max[data-class="' + cls.replace(/"/g, '\\"') + '"]');
        var mn  = el.value.trim(), mx = max ? max.value.trim() : '';
        if (mn === '' && mx === '') { if (AGE_POLICY.bands) delete AGE_POLICY.bands[cls]; return; }
        AGE_POLICY.bands = AGE_POLICY.bands || {};
        AGE_POLICY.bands[cls] = { min: mn === '' ? null : +mn, max: mx === '' ? null : +mx };
        payload['bands[' + cls + '][min]'] = mn;
        payload['bands[' + cls + '][max]'] = mx;
    });

    post('school_config/save_age_policy', payload, function (d) {
        toast(d.message, d.status === 'success');
        if (d.status === 'success') {
            // Keep the in-memory copy in step so switching tabs and back does not
            // redraw the editor from a stale policy.
            CFG.admission_age_policy = {
                mode:   document.getElementById('agePolicyMode').value,
                cutoff: document.getElementById('agePolicyCutoff').value.trim(),
                bands:  AGE_POLICY.bands || {}
            };
        }
    });
}

function renderClasses(classes) {
    var wrap = document.getElementById('classList');
    var visible = classes.filter(function(c) { return showDeleted || !c.deleted; });

    if (!visible.length) {
        wrap.innerHTML = '<div class="sc-empty"><i class="fa fa-th-large"></i>No classes defined. Click "Seed Standard Classes" to get started.</div>';
        return;
    }
    wrap.innerHTML = visible.map(function(cls, i) {
        var isDeleted = cls.deleted;
        return '<div class="sc-class-row' + (isDeleted ? ' deleted-row' : '') + '" data-key="' + esc(cls.key) + '">'
            + '<span class="sc-tag ' + (isDeleted ? 'sc-tag-red' : 'sc-tag-teal') + '" style="font-size:10px;padding:2px 6px;" title="' + (isDeleted ? 'DELETED' : 'Order') + '">' + (isDeleted ? 'DEL' : (i + 1)) + '</span>'
            + '<input type="text" class="cls-label" value="' + esc(cls.label) + '" placeholder="Class label"' + (isDeleted ? ' disabled' : '') + '>'
            + '<input type="hidden" class="cls-key" value="' + esc(cls.key) + '">'
            + '<select class="cls-type" style="padding:6px 8px;background:var(--bg2);border:1px solid var(--border);border-radius:8px;color:var(--t1);font-size:12px;"' + (isDeleted ? ' disabled' : '') + '>'
            + ['foundational','primary','middle','secondary','senior'].map(function(t) {
                return '<option value="' + t + '"' + (cls.type === t ? ' selected' : '') + '>' + t + '</option>';
              }).join('')
            + '</select>'
            + '<label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--t2);cursor:pointer;" title="Enable streams (for Cl. 11-12)">'
            + '<input type="checkbox" class="cls-streams"' + (cls.streams_enabled ? ' checked' : '') + (isDeleted ? ' disabled' : '') + '> Streams'
            + '</label>'
            + (isDeleted
                ? '<button class="sc-btn sc-btn-ghost sc-btn-sm" onclick="restoreClass(\'' + esc(cls.key) + '\')"><i class="fa fa-undo"></i> Restore</button>'
                : '<button class="sc-btn sc-btn-danger sc-btn-sm" onclick="softDeleteClass(\'' + esc(cls.key) + '\')"><i class="fa fa-trash"></i></button>')
            + '</div>';
    }).join('');
}

window.toggleDeletedClasses = function() {
    showDeleted = document.getElementById('showDeletedToggle').checked;
    renderClasses(CFG.classes || []);
};

window.addClassRow = function() {
    var wrap = document.getElementById('classList');
    var empty = wrap.querySelector('.sc-empty');
    if (empty) empty.remove();

    var row = document.createElement('div');
    row.className = 'sc-class-row';
    row.innerHTML =
        '<span class="sc-tag sc-tag-teal" style="font-size:10px;padding:2px 6px;">NEW</span>'
        + '<input type="text" class="cls-label" placeholder="e.g. Class 1st" onblur="autoFillClassKey(this)">'
        + '<input type="hidden" class="cls-key" value="">'
        + '<select class="cls-type" style="padding:6px 8px;background:var(--bg2);border:1px solid var(--border);border-radius:8px;color:var(--t1);font-size:12px;">'
        + ['foundational','primary','middle','secondary','senior'].map(function(t) {
            return '<option value="' + t + '">' + t + '</option>';
          }).join('')
        + '</select>'
        + '<label style="display:flex;align-items:center;gap:5px;font-size:12px;color:var(--t2);cursor:pointer;">'
        + '<input type="checkbox" class="cls-streams"> Streams</label>'
        + '<button class="sc-btn sc-btn-danger sc-btn-sm" onclick="this.closest(\'.sc-class-row\').remove()">'
        + '<i class="fa fa-trash"></i></button>';
    wrap.appendChild(row);
    row.querySelector('.cls-label').focus();
};

/* Auto-derive key from label for new class rows */
window.autoFillClassKey = function(input) {
    var row = input.closest('.sc-class-row');
    var keyInput = row.querySelector('.cls-key');
    if (keyInput.value) return; // already has a key

    var label = input.value.trim();
    if (!label) return;

    // Try to extract number: "Class 9th" → "9", "Nursery" → "Nursery"
    var numMatch = label.match(/(\d+)/);
    if (numMatch) {
        keyInput.value = numMatch[1];
    } else {
        // Foundational: use name directly
        var clean = label.replace(/^class\s*/i, '').trim();
        keyInput.value = clean.replace(/[^A-Za-z0-9_]/g, '');
    }
};

var DEFAULT_CLASSES = [
    { key:'Playgroup', label:'Playgroup',  type:'foundational', streams_enabled:false, deleted:false },
    { key:'Nursery',   label:'Nursery',    type:'foundational', streams_enabled:false, deleted:false },
    { key:'LKG',       label:'LKG',        type:'foundational', streams_enabled:false, deleted:false },
    { key:'UKG',       label:'UKG',        type:'foundational', streams_enabled:false, deleted:false },
    { key:'1',  label:'Class 1st',  type:'primary',   streams_enabled:false, deleted:false },
    { key:'2',  label:'Class 2nd',  type:'primary',   streams_enabled:false, deleted:false },
    { key:'3',  label:'Class 3rd',  type:'primary',   streams_enabled:false, deleted:false },
    { key:'4',  label:'Class 4th',  type:'primary',   streams_enabled:false, deleted:false },
    { key:'5',  label:'Class 5th',  type:'primary',   streams_enabled:false, deleted:false },
    { key:'6',  label:'Class 6th',  type:'middle',    streams_enabled:false, deleted:false },
    { key:'7',  label:'Class 7th',  type:'middle',    streams_enabled:false, deleted:false },
    { key:'8',  label:'Class 8th',  type:'middle',    streams_enabled:false, deleted:false },
    { key:'9',  label:'Class 9th',  type:'secondary', streams_enabled:false, deleted:false },
    { key:'10', label:'Class 10th', type:'secondary', streams_enabled:false, deleted:false },
    { key:'11', label:'Class 11th', type:'senior',    streams_enabled:true,  deleted:false },
    { key:'12', label:'Class 12th', type:'senior',    streams_enabled:true,  deleted:false },
];

window.seedDefaultClasses = function() {
    _confirmModal({
        title: 'Replace class list with standard',
        body: 'This will replace the current class list with the standard list (1-12 + Foundational). Continue?',
        confirmText: 'Replace',
        dangerous: true
    }).then(function(ok) {
        if (!ok) return;
        CFG.classes = DEFAULT_CLASSES.slice();
        renderClasses(CFG.classes);
        toast('Standard classes loaded. Click "Save Class List" to persist.');
    });
};

window.saveClasses = function() {
    // Collect from DOM (non-deleted visible rows) + keep deleted from CFG
    var rows = document.querySelectorAll('#classList .sc-class-row');
    var classes = [];
    var seenKeys = {};

    rows.forEach(function(row, i) {
        var label = row.querySelector('.cls-label').value.trim();
        var key   = row.querySelector('.cls-key').value.trim() || label.replace(/\s+/g,'_').replace(/[^A-Za-z0-9_]/g,'');
        var type  = row.querySelector('.cls-type').value;
        var streams = row.querySelector('.cls-streams').checked;
        var isDeleted = row.classList.contains('deleted-row');
        if (label && key) {
            seenKeys[key] = true;
            classes.push({ key: key, label: label, type: type, order: i, streams_enabled: streams, deleted: isDeleted });
        }
    });

    // Re-add hidden deleted classes that aren't shown
    if (!showDeleted && CFG.classes) {
        CFG.classes.forEach(function(c) {
            if (c.deleted && !seenKeys[c.key]) {
                classes.push(c);
            }
        });
    }

    if (!classes.length) { toast('Add at least one class.', false); return; }

    post('school_config/save_classes', { classes: JSON.stringify(classes) }, function(d) {
        toast(d.message, d.status === 'success');
        if (d.status === 'success') {
            CFG.classes = classes;
            renderClassSelects(classes, CFG.sessions || [], CFG.active_session || '');
        }
    });
};

/* Issue 7: Soft delete — UI-first, server if already saved */
window.softDeleteClass = function(key) {
    _confirmModal({
        title: 'Remove this class from the list',
        body: 'Removes from the working list. You can restore it later from the deleted-items view.',
        confirmText: 'Remove',
        dangerous: true
    }).then(function(ok) {
        if (!ok) return;

        /* ── PHASE 20.1c (P20-F12, second instance) ─────────────────────
         *
         * Same phantom-success defect as deleteStream, and more serious here:
         * Phase 20.1 hardened soft_delete_class() so it REFUSES while any
         * section of the class still holds active students, and Phase 20.1c
         * made it refuse when that check cannot be evaluated. This handler
         * removed the class from the local list before calling the server and
         * then discarded every error — so the operator saw the class vanish
         * precisely when the server had protected it.
         *
         * Server first; mutate local state only on confirmed success.
         */
        var _busy = softDeleteClass._busy || (softDeleteClass._busy = {});
        if (_busy[key]) return;              // duplicate-click protection
        _busy[key] = true;

        post('school_config/soft_delete_class', { class_key: key }, function(d) {
            _busy[key] = false;

            var msg = (d && d.message) ? String(d.message) : '';
            var notFound = (d && d.reason === 'not_found') || /does not exist|not found/i.test(msg);

            if ((d && d.status === 'success') || notFound) {
                CFG.classes = (CFG.classes || []).filter(function(c) { return c.key !== key; });
                renderClasses(CFG.classes);
                renderClassSelects(CFG.classes, CFG.sessions || [], CFG.active_session || '');
                if (d && d.status === 'success') toast('Class removed.');
                return;
            }

            // Refusal — the class MUST stay visible. Distinguish "cannot
            // delete" from "could not verify" so the operator knows whether to
            // reassign students or simply retry.
            if (d && d.reason === 'dependencies_undetermined') {
                toast(msg || 'Could not verify what uses this class. Nothing was changed — please retry.', false);
            } else if (d && d.dependencies) {
                toast(msg || 'Cannot remove: this class is still in use.', false);
            } else {
                toast(msg || 'Remove failed. Nothing was changed.', false);
            }
        });
    });
};

window.restoreClass = function(key) {
    _confirmModal({
        title: 'Restore this class',
        body: 'The class will be re-added to the active list.',
        confirmText: 'Restore'
    }).then(function(ok) {
        if (!ok) return;
        post('school_config/restore_class', { class_key: key }, function(d) {
            toast(d.message, d.status === 'success');
            if (d.status === 'success') {
                (CFG.classes || []).forEach(function(c) {
                    if (c.key === key) { c.deleted = false; c.deleted_at = null; }
                });
                renderClasses(CFG.classes || []);
                renderClassSelects(CFG.classes || [], CFG.sessions || [], CFG.active_session || '');
            }
        });
    });
};

/* Issue 5: Activate classes in the current session */
window.activateClassesInSession = function() {
    var sess = CFG.active_session || currentSession;
    if (!sess) { toast('No active session. Set one first.', false); return; }
    _confirmModal({
        title: 'Activate classes in "' + sess + '"',
        body: 'Create class nodes in session "' + sess + '" for all saved (non-deleted) classes?\n\nExisting class data will NOT be overwritten.',
        confirmText: 'Activate'
    }).then(function(ok) {
        if (!ok) return;
        post('school_config/activate_classes', { session: sess }, function(d) {
            toast(d.message, d.status === 'success');
        });
    });
};

/* ══════════ SELECTS (shared by Sections + Subjects tabs) ══════════ */
function renderClassSelects(classes, sessions, activeSess) {
    // Filter out deleted classes for selects
    var activeClasses = classes.filter(function(c) { return !c.deleted; });

    var secSel = document.getElementById('secClassSel');
    var subSel = document.getElementById('subClassSel');
    var opts   = activeClasses.map(function(c) { return '<option value="' + esc(c.key) + '">' + esc(c.label) + '</option>'; }).join('');
    if (secSel) secSel.innerHTML = opts || '<option value="">No classes</option>';
    if (subSel) subSel.innerHTML = opts || '<option value="">No classes</option>';

    var sessSel = document.getElementById('secSessSel');
    var sessOpts = sessions.map(function(s) {
        return '<option value="' + esc(s) + '"' + (s === activeSess ? ' selected' : '') + '>' + s + '</option>';
    }).join('');
    if (sessSel) sessSel.innerHTML = sessOpts || '<option value="">No sessions</option>';
}

/* ══════════ SECTIONS — BULK MATRIX ══════════ */
var _secOriginal = {};   // { classKey: ['A','B'] } — from server
var _secCurrent  = {};   // { classKey: ['A','B','C'] } — user edits
var _defaultLetters = ['A','B','C','D','E','F'];

/* P20.2-CAP-2 — server-computed seat detail, keyed { classKey: { label: {...} } } */
var _secSeats  = {};
var _secLabels = {};

var _availableStreams = []; // from server

function loadAllSections() {
    var sess = document.getElementById('secSessSel').value;
    if (!sess) { toast('Select a session first.', false); return; }
    // Inline (scoped) loader inside the matrix area — NOT the full-screen overlay,
    // so switching to the Sections tab doesn't blank the whole page.
    var loaderEl = document.getElementById('sectionMatrixLoader');
    loaderEl.innerHTML = '';
    loaderEl.style.display = 'block';
    var secLoaderH = (window.ZXLoader) ? ZXLoader.mount(loaderEl, { size: 96, message: 'Loading sections…' }) : null;
    document.getElementById('sectionMatrix').innerHTML = '';
    document.getElementById('secBulkBar').style.display = 'none';

    post('school_config/get_all_sections', { session: sess }, function(d) {
        if (secLoaderH) secLoaderH.destroy();
        document.getElementById('sectionMatrixLoader').style.display = 'none';
        if (d.status !== 'success') { toast(d.message, false); return; }
        _secOriginal = {};
        _secCurrent  = {};
        _availableStreams = d.streams || [];

        /* P20.2-CAP-2 — seat detail comes from the SERVER, computed by the
           canonical roster rule. Nothing here recomputes occupancy: the
           backend is the only authority, so the table and the capacity guard
           cannot disagree (the defect SEC-11 closed). */
        _secSeats = {};
        _secLabels = {};
        (d.classes || []).forEach(function(cls) {
            _secSeats[cls.key]  = cls.seats || {};
            _secLabels[cls.key] = cls.label || cls.node || cls.key;
        });

        // Collect all letters used across all classes to build column headers
        var allLetters = _defaultLetters.slice();
        (d.classes || []).forEach(function(cls) {
            if (cls.streams_enabled && cls.stream_sections) {
                // For stream-enabled classes, build composite keys like "Science A"
                var compositeKeys = [];
                Object.keys(cls.stream_sections || {}).forEach(function(stm) {
                    (cls.stream_sections[stm] || []).forEach(function(l) {
                        compositeKeys.push(stm + ' ' + l);
                    });
                });
                // Also include any plain sections
                (cls.sections || []).forEach(function(s) {
                    compositeKeys.push(s);
                });
                _secOriginal[cls.key] = compositeKeys.slice();
                _secCurrent[cls.key]  = compositeKeys.slice();
            } else {
                _secOriginal[cls.key] = (cls.sections || []).slice();
                _secCurrent[cls.key]  = (cls.sections || []).slice();
                cls.sections.forEach(function(l) {
                    if (allLetters.indexOf(l) === -1) allLetters.push(l);
                });
            }
        });
        allLetters.sort();

        renderMatrix(d.classes || [], allLetters);
        renderSeats();   // P20.2-UI-1 — strength/occupancy table below the matrix
        updateChangeCount();
    });
}
window.loadAllSections = loadAllSections;

function renderMatrix(classes, letters) {
    if (!classes.length) {
        document.getElementById('sectionMatrix').innerHTML =
            '<div style="color:var(--t3);padding:20px;text-align:center;">'
            + '<i class="fa fa-info-circle"></i> No classes found. Add classes in the Classes tab first.</div>';
        return;
    }

    var html = '<div style="overflow-x:auto;"><table class="sc-matrix-table">';

    // Header row
    html += '<thead><tr><th style="text-align:left;min-width:140px;">Class</th>';
    letters.forEach(function(l) {
        html += '<th style="width:44px;text-align:center;">' + esc(l) + '</th>';
    });
    html += '<th style="width:60px;text-align:center;font-size:11px;color:var(--t3);">Count</th>';
    html += '<th style="width:70px;text-align:center;font-size:11px;color:var(--t3);">Quick</th>';
    html += '</tr></thead><tbody>';

    classes.forEach(function(cls) {
        if (cls.streams_enabled && _availableStreams.length) {
            // Stream-enabled class: render one row per stream
            _availableStreams.forEach(function(stm) {
                html += '<tr data-classkey="' + esc(cls.key) + '" data-stream="' + esc(stm) + '">';
                html += '<td style="font-weight:600;font-size:13px;white-space:nowrap;">'
                    + '<i class="fa fa-code-fork" style="color:var(--amber);margin-right:6px;font-size:11px;"></i>'
                    + esc(cls.label) + ' <span class="sc-tag sc-tag-amber" style="font-size:10px;">' + esc(stm) + '</span></td>';
                var cur = _secCurrent[cls.key] || [];
                letters.forEach(function(l) {
                    var compositeKey = stm + ' ' + l;
                    var active = cur.indexOf(compositeKey) !== -1;
                    html += '<td style="text-align:center;">'
                        + '<button class="sec-toggle' + (active ? ' sec-on' : '') + '" '
                        + 'data-class="' + esc(cls.key) + '" data-letter="' + esc(compositeKey) + '" '
                        + 'data-stream="' + esc(stm) + '" '
                        + 'onclick="toggleSection(this)" title="Section ' + esc(compositeKey) + '">'
                        + esc(l) + '</button></td>';
                });
                var streamCount = cur.filter(function(s) { return s.indexOf(stm + ' ') === 0; }).length;
                html += '<td style="text-align:center;font-weight:700;font-size:13px;" class="sec-count-cell" data-stream="' + esc(stm) + '">'
                    + streamCount + '</td>';
                html += '<td style="text-align:center;white-space:nowrap;">'
                    + '<button class="sc-btn sc-btn-ghost" style="padding:2px 6px;font-size:10px;border-radius:4px;" '
                    + 'onclick="quickSetStreamSections(\'' + esc(cls.key) + '\',\'' + esc(stm) + '\', 1)" title="Set only ' + esc(stm) + ' A">1</button>'
                    + '<button class="sc-btn sc-btn-ghost" style="padding:2px 6px;font-size:10px;border-radius:4px;" '
                    + 'onclick="quickSetStreamSections(\'' + esc(cls.key) + '\',\'' + esc(stm) + '\', 2)" title="Set ' + esc(stm) + ' A, B">2</button>'
                    + '</td>';
                html += '</tr>';
            });
        } else {
            // Normal class: one row with plain letter sections
            html += '<tr data-classkey="' + esc(cls.key) + '">';
            html += '<td style="font-weight:600;font-size:13px;white-space:nowrap;">'
                + '<i class="fa fa-graduation-cap" style="color:var(--gold);margin-right:6px;font-size:11px;"></i>'
                + esc(cls.label) + '</td>';
            var cur = _secCurrent[cls.key] || [];
            letters.forEach(function(l) {
                var active = cur.indexOf(l) !== -1;
                html += '<td style="text-align:center;">'
                    + '<button class="sec-toggle' + (active ? ' sec-on' : '') + '" '
                    + 'data-class="' + esc(cls.key) + '" data-letter="' + esc(l) + '" '
                    + 'onclick="toggleSection(this)" title="Section ' + esc(l) + '">'
                    + esc(l) + '</button></td>';
            });
            html += '<td style="text-align:center;font-weight:700;font-size:13px;" class="sec-count-cell">'
                + cur.length + '</td>';
            // 2026-05-15 Phase 3 — inline onclick replaced by data-attribute
            // + delegated click handler (bound once below). The previous
            // pattern `onclick="quickSetSections('` + esc(cls.key) + `', 1)"`
            // HTML-escaped cls.key, but the browser un-escapes HTML entities
            // inside attribute values BEFORE the JS parser sees them — so a
            // class key containing `'` would break out of the JS string
            // context. The current save_classes() validation already
            // restricts keys to /^[A-Za-z0-9_]+$/, but defense-in-depth via
            // data-attributes is the correct fix.
            html += '<td style="text-align:center;white-space:nowrap;">'
                + '<button class="sc-btn sc-btn-ghost js-quick-sec" style="padding:2px 6px;font-size:10px;border-radius:4px;" '
                + 'data-quick-class="' + esc(cls.key) + '" data-quick-count="1" title="Set only Section A">1</button>'
                + '<button class="sc-btn sc-btn-ghost js-quick-sec" style="padding:2px 6px;font-size:10px;border-radius:4px;" '
                + 'data-quick-class="' + esc(cls.key) + '" data-quick-count="3" title="Set A, B, C">3</button>'
                + '<button class="sc-btn sc-btn-ghost js-quick-sec" style="padding:2px 6px;font-size:10px;border-radius:4px;" '
                + 'data-quick-class="' + esc(cls.key) + '" data-quick-count="6" title="Set A through F">6</button>'
                + '</td>';
            html += '</tr>';
        }
    });

    // Bulk row at bottom (for non-stream classes only)
    html += '<tr style="border-top:2px solid var(--border);background:var(--bg3);">';
    html += '<td style="font-size:12px;color:var(--t2);font-weight:600;">Apply to ALL (non-stream)</td>';
    letters.forEach(function(l) {
        html += '<td style="text-align:center;">'
            + '<button class="sec-toggle-all" data-letter="' + esc(l) + '" '
            + 'onclick="toggleColumnAll(this)" title="Toggle ' + esc(l) + ' for all classes" '
            + 'style="font-size:10px;">' + esc(l) + '</button></td>';
    });
    html += '<td></td><td></td></tr>';

    html += '</tbody></table></div>';

    // Inject styles for toggle buttons
    html += '<style>'
        + '.sc-matrix-table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px;}'
        + '.sc-matrix-table th{padding:8px 4px;font-size:11px;font-weight:600;color:var(--t2);'
        + 'text-transform:uppercase;letter-spacing:.5px;border-bottom:2px solid var(--border);}'
        + '.sc-matrix-table td{padding:6px 4px;border-bottom:1px solid var(--border);vertical-align:middle;}'
        + '.sc-matrix-table tbody tr:hover{background:var(--gold-dim);}'
        + '.sec-toggle{width:34px;height:34px;border-radius:8px;border:2px solid var(--border);'
        + 'background:var(--bg2);color:var(--t3);font-weight:700;font-size:13px;cursor:pointer;'
        + 'transition:all .15s var(--ease);display:inline-flex;align-items:center;justify-content:center;}'
        + '.sec-toggle:hover{border-color:var(--gold);color:var(--t1);transform:scale(1.08);}'
        + '.sec-toggle.sec-on{background:var(--gold);border-color:var(--gold);color:#fff;box-shadow:0 2px 8px var(--gold-glow);}'
        + '.sec-toggle.sec-on:hover{background:var(--gold2);}'
        + '.sec-toggle.sec-changed{box-shadow:0 0 0 2px var(--amber);}'
        + '.sec-toggle-all{width:28px;height:28px;border-radius:6px;border:1px dashed var(--border);'
        + 'background:transparent;color:var(--t3);font-weight:600;cursor:pointer;transition:all .15s var(--ease);'
        + 'display:inline-flex;align-items:center;justify-content:center;}'
        + '.sec-toggle-all:hover{border-color:var(--gold);color:var(--gold);background:var(--gold-dim);}'
        + '</style>';

    var matrixHost = document.getElementById('sectionMatrix');
    matrixHost.innerHTML = html;

    // 2026-05-15 Phase 3 — delegated click handler for the quick-set-N
    // buttons rendered inside #sectionMatrix. Bound at render-time (so the
    // host element is guaranteed to exist) and guarded by __quickSecBound
    // so subsequent renders don't stack additional listeners. Replaces the
    // prior inline `onclick="quickSetSections('+esc(cls.key)+',1)"` which
    // interpolated cls.key into a JS string literal — a defense-in-depth
    // hardening against malformed class keys breaking out of the string
    // context. The buttons now carry data-quick-class + data-quick-count.
    if (!matrixHost.__quickSecBound) {
        matrixHost.__quickSecBound = true;
        matrixHost.addEventListener('click', function(ev) {
            var btn = ev.target.closest('.js-quick-sec');
            if (!btn || !matrixHost.contains(btn)) return;
            var classKey = btn.getAttribute('data-quick-class') || '';
            var count    = parseInt(btn.getAttribute('data-quick-count'), 10);
            if (!classKey || !(count > 0)) return;
            window.quickSetSections(classKey, count);
        });
    }
}

window.toggleSection = function(btn) {
    var classKey = btn.getAttribute('data-class');
    var letter   = btn.getAttribute('data-letter'); // plain "A" or composite "Science A"
    var streamName = btn.getAttribute('data-stream') || '';
    var cur      = _secCurrent[classKey] || [];
    var idx      = cur.indexOf(letter);
    if (idx !== -1) {
        cur.splice(idx, 1);
        btn.classList.remove('sec-on');
    } else {
        cur.push(letter);
        cur.sort();
        btn.classList.add('sec-on');
    }
    _secCurrent[classKey] = cur;

    // Mark changed vs original
    var orig = _secOriginal[classKey] || [];
    var changed = (orig.indexOf(letter) !== -1) !== (cur.indexOf(letter) !== -1);
    btn.classList.toggle('sec-changed', changed);

    // Update count cell
    var row = btn.closest('tr');
    if (row) {
        var countCell = row.querySelector('.sec-count-cell');
        if (countCell) {
            if (streamName) {
                // Count only sections for this stream
                var streamCount = cur.filter(function(s) { return s.indexOf(streamName + ' ') === 0; }).length;
                countCell.textContent = streamCount;
            } else {
                countCell.textContent = cur.length;
            }
        }
    }
    updateChangeCount();
};

window.quickSetSections = function(classKey, count) {
    var letters = _defaultLetters.slice(0, count);
    _secCurrent[classKey] = letters.slice();
    // Re-render the buttons in that row
    var row = document.querySelector('tr[data-classkey="' + classKey + '"]:not([data-stream])');
    if (!row) return;
    row.querySelectorAll('.sec-toggle').forEach(function(btn) {
        var l = btn.getAttribute('data-letter');
        var on = letters.indexOf(l) !== -1;
        btn.classList.toggle('sec-on', on);
        var orig = _secOriginal[classKey] || [];
        var changed = (orig.indexOf(l) !== -1) !== on;
        btn.classList.toggle('sec-changed', changed);
    });
    var countCell = row.querySelector('.sec-count-cell');
    if (countCell) countCell.textContent = letters.length;
    updateChangeCount();
};

window.quickSetStreamSections = function(classKey, streamName, count) {
    var letters = _defaultLetters.slice(0, count);
    var cur = _secCurrent[classKey] || [];
    // Remove all existing sections for this stream
    cur = cur.filter(function(s) { return s.indexOf(streamName + ' ') !== 0; });
    // Add new stream sections
    letters.forEach(function(l) { cur.push(streamName + ' ' + l); });
    _secCurrent[classKey] = cur;
    // Re-render the buttons in that stream row
    var row = document.querySelector('tr[data-classkey="' + classKey + '"][data-stream="' + streamName + '"]');
    if (!row) return;
    row.querySelectorAll('.sec-toggle').forEach(function(btn) {
        var compositeKey = btn.getAttribute('data-letter');
        var on = cur.indexOf(compositeKey) !== -1;
        btn.classList.toggle('sec-on', on);
        var orig = _secOriginal[classKey] || [];
        var changed = (orig.indexOf(compositeKey) !== -1) !== on;
        btn.classList.toggle('sec-changed', changed);
    });
    var countCell = row.querySelector('.sec-count-cell');
    if (countCell) countCell.textContent = count;
    updateChangeCount();
};

window.toggleColumnAll = function(btn) {
    var letter = btn.getAttribute('data-letter');
    // Only apply to non-stream classes
    var nonStreamKeys = [];
    Object.keys(_secCurrent).forEach(function(k) {
        // Check if any section contains a stream prefix — if so, it's a stream class
        var hasStream = _secCurrent[k].some(function(s) { return s.indexOf(' ') !== -1; });
        // Also check if _availableStreams has entries and this key is 11 or 12
        if (!hasStream && ['11','12'].indexOf(k) === -1) nonStreamKeys.push(k);
        else if (!hasStream) nonStreamKeys.push(k); // 11/12 with no stream sections yet = treat as non-stream
    });

    var onCount = 0;
    nonStreamKeys.forEach(function(k) {
        if (_secCurrent[k].indexOf(letter) !== -1) onCount++;
    });
    var shouldAdd = onCount <= nonStreamKeys.length / 2;

    nonStreamKeys.forEach(function(classKey) {
        var cur = _secCurrent[classKey];
        var idx = cur.indexOf(letter);
        if (shouldAdd && idx === -1) {
            cur.push(letter);
            cur.sort();
        } else if (!shouldAdd && idx !== -1) {
            cur.splice(idx, 1);
        }
        _secCurrent[classKey] = cur;
    });
    // Re-render all toggle buttons for this letter (non-stream only)
    document.querySelectorAll('.sec-toggle[data-letter="' + letter + '"]:not([data-stream])').forEach(function(b) {
        var ck = b.getAttribute('data-class');
        var on = _secCurrent[ck].indexOf(letter) !== -1;
        b.classList.toggle('sec-on', on);
        var orig = _secOriginal[ck] || [];
        b.classList.toggle('sec-changed', (orig.indexOf(letter) !== -1) !== on);
        var row = b.closest('tr');
        if (row) {
            var cc = row.querySelector('.sec-count-cell');
            if (cc) cc.textContent = _secCurrent[ck].length;
        }
    });
    updateChangeCount();
};

function updateChangeCount() {
    var count = 0;
    Object.keys(_secCurrent).forEach(function(k) {
        var orig = (_secOriginal[k] || []).slice().sort().join(',');
        var cur  = (_secCurrent[k]  || []).slice().sort().join(',');
        if (orig !== cur) count++;
    });
    var bar = document.getElementById('secBulkBar');
    bar.style.display = count > 0 ? 'flex' : 'none';
    document.getElementById('secChangeCount').textContent = count;
}

window.discardSectionChanges = function() {
    // Reset current to original
    Object.keys(_secOriginal).forEach(function(k) {
        _secCurrent[k] = _secOriginal[k].slice();
    });
    loadAllSections(); // re-render
};

window.saveBulkSections = function() {
    var sess = document.getElementById('secSessSel').value;
    var changes = [];

    Object.keys(_secCurrent).forEach(function(classKey) {
        var orig = (_secOriginal[classKey] || []).slice().sort();
        var cur  = (_secCurrent[classKey]  || []).slice().sort();
        if (orig.join(',') === cur.join(',')) return; // no change

        var toAdd    = cur.filter(function(l) { return orig.indexOf(l) === -1; });
        var toRemove = orig.filter(function(l) { return cur.indexOf(l) === -1; });
        if (toAdd.length || toRemove.length) {
            changes.push({ class_key: classKey, add: toAdd, remove: toRemove });
        }
    });

    if (!changes.length) { toast('No changes to save.'); return; }

    var btn = document.getElementById('secBulkSaveBtn');
    btn.disabled = true;

    post('school_config/bulk_save_sections', { session: sess, changes: JSON.stringify(changes) }, function(d) {
        btn.disabled = false;
        toast(d.message, d.status === 'success');

        // 2026-05-15 Phase 3 — surface per-row failures. Phase 2 server-side
        // hardening packs unsuccessful section writes into d.failed[] (or
        // d.data.failed[]) with { class_key, section, reason } records.
        // Previously the UI only showed the top-line summary message, so a
        // partial-success "5 of 7 saved" silently dropped the 2 failures.
        // We render them inline below the matrix so the operator can act.
        var failed = (d && d.failed) ? d.failed
                    : (d && d.data && d.data.failed) ? d.data.failed : [];
        renderBulkSectionFailures(failed);

        if (d.status === 'success') {
            loadAllSections(); // refresh from server
        }
    });
};

// 2026-05-15 Phase 3 — partial-failure renderer for bulk_save_sections.
// Idempotent: re-rendering with an empty array clears the previous panel.
function renderBulkSectionFailures(failed) {
    var host = document.getElementById('secBulkFailures');
    if (!host) {
        host = document.createElement('div');
        host.id = 'secBulkFailures';
        host.style.cssText = 'margin-top:12px;';
        var matrix = document.getElementById('sectionMatrix');
        if (matrix && matrix.parentNode) matrix.parentNode.insertBefore(host, matrix.nextSibling);
    }
    if (!failed || !failed.length) { host.innerHTML = ''; return; }

    var rows = '';
    failed.forEach(function(f) {
        var ck  = esc(f.class_key || f.classKey || '?');
        var sec = esc(f.section   || f.letter   || '?');
        var why = esc(f.reason    || f.message  || 'unknown error');
        rows += '<tr><td style="padding:4px 8px;">' + ck + '</td>'
              + '<td style="padding:4px 8px;">' + sec + '</td>'
              + '<td style="padding:4px 8px;color:var(--danger,#c00);">' + why + '</td></tr>';
    });
    host.innerHTML =
        '<div style="border:1px solid var(--danger,#c00);border-radius:6px;padding:10px;background:rgba(192,0,0,0.06);">'
      + '<div style="font-weight:600;margin-bottom:6px;color:var(--danger,#c00);">'
      + '<i class="fa fa-exclamation-triangle"></i> '
      + failed.length + ' row(s) failed to save'
      + '</div>'
      + '<table style="width:100%;font-size:12px;border-collapse:collapse;">'
      + '<thead><tr style="text-align:left;color:var(--t2);">'
      + '<th style="padding:4px 8px;">Class</th><th style="padding:4px 8px;">Section</th><th style="padding:4px 8px;">Reason</th>'
      + '</tr></thead>'
      + '<tbody>' + rows + '</tbody></table></div>';
}

/* Legacy single-class add (for custom section letters beyond F) */
window.addSection = function() {
    var classKey = document.getElementById('secClassSel').value;
    var sess     = document.getElementById('secSessSel').value;
    var letter   = document.getElementById('newSectionInput').value.trim().toUpperCase();
    if (!classKey) { toast('Select a class.', false); return; }
    if (!letter) { toast('Enter a section letter (A-Z).', false); return; }
    post('school_config/save_section', { class_key: classKey, section: letter, session: sess }, function(d) {
        toast(d.message, d.status === 'success');
        if (d.status === 'success') {
            document.getElementById('newSectionInput').value = '';
            loadAllSections(); // refresh matrix
        }
    });
};

/* Keep legacy functions wired (old loadSections still used internally) */
window.loadSections = loadAllSections;
window.deleteSection = function(classKey, letter, sess) {
    _confirmModal({
        title: 'Delete Section ' + letter,
        body: 'This cannot be undone.',
        confirmText: 'Delete',
        dangerous: true
    }).then(function(ok) {
        if (!ok) return;
        post('school_config/delete_section', { class_key: classKey, section: letter, session: sess }, function(d) {
            toast(d.message, d.status === 'success');
            if (d.status === 'success') loadAllSections();
        });
    });
};

/* ══════════ PHASE 20.2 — SECTION STRENGTH & OCCUPANCY (P20.2-UI-1) ══════════
   Renders server-computed seat data. Deliberately performs NO occupancy or
   capacity arithmetic of its own beyond formatting: the backend resolves both
   through the canonical engine, and duplicating either rule in JS is how a UI
   starts disagreeing with the guard that actually blocks admissions. */

function renderSeats() {
    var body    = document.getElementById('seatsBody');
    var panel   = document.getElementById('seatsPanel');
    var summary = document.getElementById('seatsSummary');
    if (!body || !panel) return;

    var rows = [], totCap = 0, totOcc = 0, nSec = 0, unset = 0, anyUnknown = false;

    Object.keys(_secSeats).forEach(function(classKey) {
        var seats = _secSeats[classKey] || {};
        Object.keys(seats).sort().forEach(function(label) {
            var s = seats[label] || {};
            nSec++;
            totCap += (s.capacity || 0);
            if (s.occupied === null || s.occupied === undefined) { anyUnknown = true; }
            else { totOcc += s.occupied; }
            if (!s.capacity_configured) unset++;

            var occTxt = (s.occupied === null || s.occupied === undefined) ? '—' : s.occupied;
            var avlTxt = (s.available === null || s.available === undefined) ? '—' : s.available;

            var capTxt = s.capacity_configured
                ? '<strong>' + s.capacity + '</strong>'
                : '<span style="color:#b45309;">' + s.capacity + '</span> '
                  + '<small style="color:#b45309;">not set</small>';

            var status;
            if (s.occupied === null || s.occupied === undefined) {
                status = '<span class="label label-default">Unknown</span>';
            } else if (s.over > 0) {
                status = '<span class="label label-danger">Over by ' + s.over + '</span>';
            } else if (s.available === 0) {
                status = '<span class="label label-warning">Full</span>';
            } else {
                status = '<span class="label label-success">Open</span>';
            }

            rows.push(
                '<tr>'
              + '<td>' + esc(_secLabels[classKey] || classKey) + '</td>'
              + '<td>' + esc(label) + '</td>'
              + '<td style="text-align:right;">' + capTxt + '</td>'
              + '<td style="text-align:right;">' + occTxt + '</td>'
              + '<td style="text-align:right;">' + avlTxt + '</td>'
              + '<td>' + status + '</td>'
              + '<td style="text-align:right;">'
              +   '<button type="button" class="btn btn-xs btn-default" '
              +   'onclick="openCapacityEditor(\'' + esc(classKey) + '\',\'' + esc(label) + '\')">'
              +   '<i class="fa fa-pencil"></i> Capacity</button>'
              + '</td>'
              + '</tr>'
            );
        });
    });

    if (!rows.length) {
        panel.style.display = 'none';
        return;
    }

    body.innerHTML = rows.join('');
    summary.innerHTML = nSec + ' section' + (nSec === 1 ? '' : 's')
        + ' · ' + totCap + ' seats'
        + (anyUnknown ? ' · occupancy partially unavailable' : ' · ' + totOcc + ' occupied')
        + (unset ? ' · <span style="color:#b45309;">' + unset + ' without configured capacity</span>' : '');
    // Apply the collapse state BEFORE revealing the panel, otherwise the table
    // paints expanded for a frame and then snaps shut. loadAllSections()
    // rebuilds this panel on every session switch and after every capacity
    // save, so this also stops it silently re-expanding.
    applySeatsCollapsed(_seatsPrefRead());
    panel.style.display = 'block';
}

/* ── Collapse / expand the seats table ──────────────────────────────────────
   The table runs one row per section (35 on a mid-size school), which pushes
   the section matrix off screen. Collapsing keeps the summary line visible —
   totals and the "N without configured capacity" warning stay readable without
   expanding, so nothing actionable is hidden by collapsing.

   The choice is remembered per browser. localStorage may throw in private
   modes, so every access is guarded and simply degrades to "not remembered". */
var SEATS_COLLAPSE_KEY = 'zx_seats_collapsed';

/* Default is COLLAPSED. The table runs one row per section (35 on a mid-size
   school) and would otherwise push the section matrix — the primary control on
   this tab — off screen on every load. The summary line stays visible when
   collapsed, so totals and the "N without configured capacity" warning are
   still readable without expanding.

   Only an explicit stored '0' (the operator expanded it) opens it. A missing
   key, or localStorage throwing in private mode, both fall back to collapsed. */
function _seatsPrefRead() {
    try { return localStorage.getItem(SEATS_COLLAPSE_KEY) !== '0'; }
    catch (e) { return true; }
}
function _seatsPrefWrite(collapsed) {
    try { localStorage.setItem(SEATS_COLLAPSE_KEY, collapsed ? '1' : '0'); }
    catch (e) { /* private mode — not remembered, still works this session */ }
}

function applySeatsCollapsed(collapsed) {
    var wrap     = document.getElementById('seatsBodyWrap');
    var note     = document.getElementById('seatsFootnote');
    var chevron  = document.getElementById('seatsChevron');
    var toggle   = document.getElementById('seatsToggle');
    if (!wrap) return;

    wrap.style.display = collapsed ? 'none' : '';
    if (note)    note.style.display = collapsed ? 'none' : '';
    if (chevron) chevron.style.transform = collapsed ? 'rotate(-90deg)' : '';
    if (toggle)  toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
}

window.toggleSeats = function(ev) {
    if (ev && ev.preventDefault) ev.preventDefault();
    var wrap = document.getElementById('seatsBodyWrap');
    if (!wrap) return;
    var collapsed = (wrap.style.display !== 'none');   // about to become collapsed
    applySeatsCollapsed(collapsed);
    _seatsPrefWrite(collapsed);
};

window.openCapacityEditor = function(classKey, label) {
    var s = (_secSeats[classKey] || {})[label] || {};
    document.getElementById('capClassKey').value = classKey;
    document.getElementById('capSection').value  = label;
    document.getElementById('capClassName').textContent = (_secLabels[classKey] || classKey) + ' — Section ' + label;
    document.getElementById('capCurrent').textContent   =
        s.capacity + (s.capacity_configured ? '' : ' (not configured — engine fallback)');
    document.getElementById('capOccupied').textContent  =
        (s.occupied === null || s.occupied === undefined) ? 'unavailable' : s.occupied;
    document.getElementById('capInput').value = s.capacity_configured ? s.capacity : '';
    document.getElementById('capWarn').style.display = 'none';
    // Reset the confirm ROW too, not just the checkbox: it is revealed by a
    // confirm_required refusal and is never hidden again, so opening a second
    // section afterwards showed it an over-capacity confirm it had not earned.
    document.getElementById('capConfirmRow').style.display = 'none';
    document.getElementById('capConfirm').checked = false;
    document.getElementById('capSaveBtn').disabled = false;
    $('#capacityModal').modal('show');
};

window.saveCapacity = function() {
    var btn       = document.getElementById('capSaveBtn');
    var classKey  = document.getElementById('capClassKey').value;
    var section   = document.getElementById('capSection').value;
    var capacity  = document.getElementById('capInput').value.trim();
    var confirmed = document.getElementById('capConfirm').checked ? '1' : '0';
    var sess      = document.getElementById('secSessSel').value;

    if (!/^\d{1,4}$/.test(capacity) || parseInt(capacity, 10) < 1) {
        toast('Capacity must be a whole number of at least 1.', false);
        return;
    }

    btn.disabled = true;                       // duplicate-submit protection
    post('school_config/save_section_capacity', {
        class_key: classKey, section: section, session: sess,
        capacity: capacity, confirm_over_capacity: confirmed
    }, function(d) {
        btn.disabled = false;

        if (d && d.status === 'success') {
            $('#capacityModal').modal('hide');
            toast(d.message, true);
            loadAllSections();                 // re-read from the server
            return;
        }

        /* Reduction below occupancy — the server refused pending explicit
           confirmation and told us WHY, with real numbers. Show its message
           rather than inventing one client-side. Nothing was saved. */
        if (d && d.reason === 'confirm_required') {
            var w = document.getElementById('capWarn');
            w.innerHTML = '<i class="fa fa-exclamation-triangle"></i> ' + esc(d.message)
                        + '<br><small>No student will be removed, transferred or renumbered.</small>';
            w.style.display = 'block';
            document.getElementById('capConfirmRow').style.display = 'block';
            return;
        }

        toast((d && d.message) ? d.message : 'Capacity was not saved.', false);
    });
};

/* ══════════ SUBJECTS ══════════ */

// Reset subject editor when class dropdown changes
document.getElementById('subClassSel').addEventListener('change', function() {
    document.getElementById('subEditorWrap').style.display = 'none';
    _subClassKey = '';
    _subItems = [];
});

/* ══════════ SUBJECT CONFIGURATION (v2) ══════════ */
var _subClassKey = '';      // current class being edited
var _subItems = [];         // working list: [{name,category,stream,code}]
var _subHasStreams = false;
var _subStreams = [];        // ['Science','Commerce','Arts']
var _subActiveStream = 'all'; // current stream tab filter

window.subLoadClass = function() {
    var classKey = document.getElementById('subClassSel').value;
    if (!classKey) { toast('Select a class.', false); return; }
    _subClassKey = classKey;
    _subActiveStream = 'all';

    var sel = document.getElementById('subClassSel');
    var lbl = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : classKey;
    document.getElementById('subEditorClassLabel').textContent = lbl;

    // Load existing subjects first
    post('school_config/get_subjects', { class_key: classKey }, function(d) {
        if (d.status === 'success' && d.subjects && d.subjects.length > 0) {
            // School already has subjects for this class — load them for editing
            _subItems = d.subjects.map(function(s) {
                return { name: s.name, category: s.category||'Core', stream: s.stream||'common', code: s.code||'' };
            });
            _subDetectStreams();
            document.getElementById('subEditorInfo').textContent =
                d.subjects.length + ' subjects currently configured. Edit below, then Save.';
            _subShowEditor();
        } else {
            // No subjects — load defaults
            subResetDefaults();
        }
    });
};

window.subResetDefaults = function() {
    if (!_subClassKey) { toast('Select a class first.', false); return; }

    post('school_config/get_default_subjects', { class_key: _subClassKey }, function(d) {
        if (d.status !== 'success') { toast(d.message, false); return; }

        _subItems = [];
        var defaults = d.defaults || {};

        // Build flat list from defaults (common + stream-specific)
        Object.keys(defaults).forEach(function(streamKey) {
            var subjects = defaults[streamKey];
            if (!Array.isArray(subjects)) return;
            subjects.forEach(function(s) {
                _subItems.push({
                    name: s.name,
                    category: s.category || 'Core',
                    stream: streamKey,
                    code: ''
                });
            });
        });

        _subHasStreams = d.hasStreams;
        _subStreams = d.streams || [];
        document.getElementById('subEditorInfo').textContent =
            'Recommended subjects loaded for ' + d.classRange + '. Edit as needed, then click Save.';
        _subShowEditor();
    });
};

function _subDetectStreams() {
    var streamSet = {};
    _subItems.forEach(function(s) {
        if (s.stream && s.stream !== 'common') streamSet[s.stream] = true;
    });
    _subStreams = Object.keys(streamSet);
    _subHasStreams = _subStreams.length > 0;
}

function _subShowEditor() {
    document.getElementById('subEditorWrap').style.display = 'block';

    // Stream tabs
    var tabBar = document.getElementById('subStreamTabBar');
    var tabWrap = document.getElementById('subStreamTabs');
    if (_subHasStreams) {
        var tabs = '<button class="sc-btn sc-btn-sm _sub-tab active" data-stream="all" onclick="_subSwitchTab(\'all\')" style="border-radius:8px 8px 0 0;border-bottom:2px solid var(--gold);">All</button>';
        tabs += '<button class="sc-btn sc-btn-sm _sub-tab" data-stream="common" onclick="_subSwitchTab(\'common\')" style="border-radius:8px 8px 0 0;">Common</button>';
        _subStreams.forEach(function(st) {
            tabs += '<button class="sc-btn sc-btn-sm _sub-tab" data-stream="'+esc(st)+'" onclick="_subSwitchTab(\''+esc(st)+'\')" style="border-radius:8px 8px 0 0;">'+esc(st)+'</button>';
        });
        tabBar.innerHTML = tabs;
        tabWrap.style.display = 'block';

        // Show stream in add-new row
        var nsSel = document.getElementById('subNewStream');
        nsSel.innerHTML = '<option value="common">Common</option>';
        _subStreams.forEach(function(st) {
            nsSel.innerHTML += '<option value="'+esc(st)+'">'+esc(st)+'</option>';
        });
        document.getElementById('subNewStreamWrap').style.display = 'block';
    } else {
        tabWrap.style.display = 'none';
        document.getElementById('subNewStreamWrap').style.display = 'none';
    }

    _subRender();
}

window._subSwitchTab = function(stream) {
    _subActiveStream = stream;
    document.querySelectorAll('._sub-tab').forEach(function(b) {
        var isCur = b.getAttribute('data-stream') === stream;
        b.classList.toggle('active', isCur);
        b.style.borderBottom = isCur ? '2px solid var(--gold)' : 'none';
        b.style.fontWeight = isCur ? '700' : '400';
    });
    _subRender();
};

function _subRender() {
    var el = document.getElementById('subEditList');
    var filtered = _subItems.filter(function(s, idx) {
        s._idx = idx; // preserve original index
        if (_subActiveStream === 'all') return true;
        return (s.stream || 'common') === _subActiveStream;
    });

    if (!filtered.length) {
        el.innerHTML = '<div class="sc-empty" style="padding:30px;text-align:center;"><i class="fa fa-inbox" style="font-size:24px;color:var(--t3);"></i>'
            + '<div style="margin-top:8px;color:var(--t3);">No subjects' + (_subActiveStream !== 'all' ? ' for ' + esc(_subActiveStream) : '') + '. Add one below.</div></div>';
        _subUpdateSummary();
        return;
    }

    var catColors = {
        Core:'var(--gold)', Elective:'#8b5cf6', Language:'#2563eb',
        Additional:'#f97316', Vocational:'#ec4899', Assessment:'#6b7280'
    };

    var html = filtered.map(function(s) {
        var i = s._idx;
        var cc = catColors[s.category] || 'var(--t3)';
        var streamBadge = '';
        if (_subHasStreams && s.stream && s.stream !== 'common') {
            streamBadge = '<span class="sc-tag sc-tag-amber" style="font-size:10px;">'+esc(s.stream)+'</span>';
        }

        return '<div class="_sub-row" data-idx="'+i+'" style="display:flex;align-items:center;gap:8px;padding:8px 12px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;background:var(--bg2);transition:all .15s;">'
            + '<span style="width:8px;height:8px;border-radius:50%;background:'+cc+';flex-shrink:0;" title="'+esc(s.category)+'"></span>'
            + '<input type="text" value="'+esc(s.name)+'" class="_sub-name" data-idx="'+i+'" style="flex:2;min-width:120px;border:1px solid transparent;background:transparent;color:var(--t1);font-size:13px;font-weight:500;padding:4px 8px;border-radius:6px;outline:none;" onfocus="this.style.borderColor=\'var(--gold)\';this.style.background=\'var(--bg)\'" onblur="this.style.borderColor=\'transparent\';this.style.background=\'transparent\';_subUpdateItem('+i+',\'name\',this.value)">'
            + '<select class="_sub-cat" data-idx="'+i+'" onchange="_subUpdateItem('+i+',\'category\',this.value)" style="flex:0 0 110px;height:30px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:11.5px;padding:0 6px;">'
            + ['Core','Elective','Language','Additional','Vocational','Assessment'].map(function(c){ return '<option value="'+c+'"'+(s.category===c?' selected':'')+'>'+c+'</option>'; }).join('')
            + '</select>'
            + streamBadge
            + '<button onclick="_subRemoveItem('+i+')" class="sc-btn sc-btn-danger sc-btn-sm" style="padding:4px 8px;flex-shrink:0;" title="Remove"><i class="fa fa-times"></i></button>'
            + '</div>';
    }).join('');

    el.innerHTML = html;
    _subUpdateSummary();
}

window._subUpdateItem = function(idx, field, value) {
    if (_subItems[idx]) _subItems[idx][field] = value.trim();
};

window._subRemoveItem = function(idx) {
    _subItems.splice(idx, 1);
    _subRender();
};

window.subAddRow = function() {
    var name = document.getElementById('subNewName').value.trim();
    if (!name) { toast('Enter a subject name.', false); return; }

    // Check duplicate
    var stream = _subHasStreams ? document.getElementById('subNewStream').value : 'common';
    var exists = _subItems.some(function(s) {
        return s.name.toLowerCase() === name.toLowerCase() && (s.stream||'common') === stream;
    });
    if (exists) { toast('Subject already in the list.', false); return; }

    _subItems.push({
        name: name,
        category: document.getElementById('subNewCat').value,
        stream: stream,
        code: ''
    });
    document.getElementById('subNewName').value = '';
    _subRender();
    // Scroll to bottom
    var el = document.getElementById('subEditList');
    el.scrollTop = el.scrollHeight;
};

function _subUpdateSummary() {
    var counts = {};
    _subItems.forEach(function(s) {
        var c = s.category || 'Core';
        counts[c] = (counts[c] || 0) + 1;
    });
    var catColors = {
        Core:'var(--gold)', Elective:'#8b5cf6', Language:'#2563eb',
        Additional:'#f97316', Vocational:'#ec4899', Assessment:'#6b7280'
    };
    var html = '<strong style="color:var(--t1);">Total: '+_subItems.length+'</strong>';
    Object.keys(counts).forEach(function(c) {
        var cc = catColors[c] || 'var(--t3)';
        html += '<span><span style="display:inline-block;width:8px;height:8px;border-radius:50%;background:'+cc+';margin-right:4px;"></span>'+c+': '+counts[c]+'</span>';
    });
    if (_subHasStreams) {
        var sc = {common:0};
        _subStreams.forEach(function(st){sc[st]=0;});
        _subItems.forEach(function(s){ var k=s.stream||'common'; sc[k]=(sc[k]||0)+1; });
        Object.keys(sc).forEach(function(k) {
            if(sc[k]) html += '<span style="color:var(--t3);">'+esc(k===''||k==='common'?'Common':k)+': '+sc[k]+'</span>';
        });
    }
    document.getElementById('subSummary').innerHTML = html;
}

window.subSaveAll = function() {
    if (!_subClassKey) { toast('No class selected.', false); return; }

    // Read latest values from inputs
    document.querySelectorAll('._sub-name').forEach(function(inp) {
        var idx = parseInt(inp.getAttribute('data-idx'));
        if (_subItems[idx]) _subItems[idx].name = inp.value.trim();
    });
    document.querySelectorAll('._sub-cat').forEach(function(sel) {
        var idx = parseInt(sel.getAttribute('data-idx'));
        if (_subItems[idx]) _subItems[idx].category = sel.value;
    });

    // Filter empty names
    var valid = _subItems.filter(function(s) { return s.name.trim() !== ''; });
    if (!valid.length) { toast('Add at least one subject.', false); return; }

    var btn = document.getElementById('subSaveBtn');
    btn.disabled = true;

    post('school_config/save_bulk_subjects', {
        class_key: _subClassKey,
        subjects: JSON.stringify(valid)
    }, function(d) {
        btn.disabled = false;
        toast(d.message, d.status === 'success');

        // 2026-05-15 Phase 3 — surface per-subject failures so a
        // "9 of 10 saved" outcome no longer silently drops the 1 row.
        var failed = (d && d.failed) ? d.failed
                    : (d && d.data && d.data.failed) ? d.data.failed : [];
        renderBulkSubjectFailures(failed);
        // A subject the operator removed can legitimately survive the save —
        // it is still assigned to teachers, or the dependency check could not
        // be evaluated. It reappears in the list either way, so say WHY rather
        // than letting it look like the delete silently failed.
        renderKeptSubjects((d && d.kept) ? d.kept : []);

        if (d.status === 'success') {
            // Reload to get generated codes
            post('school_config/get_subjects', { class_key: _subClassKey }, function(r) {
                if (r.status === 'success' && r.subjects) {
                    _subItems = r.subjects.map(function(s) {
                        return { name:s.name, category:s.category||'Core', stream:s.stream||'common', code:s.code||'' };
                    });
                    _subDetectStreams();
                    _subRender();
                }
            });
        }
    });
};

// 2026-05-15 Phase 3 — partial-failure renderer for save_bulk_subjects.
function renderBulkSubjectFailures(failed) {
    var host = document.getElementById('subBulkFailures');
    if (!host) {
        host = document.createElement('div');
        host.id = 'subBulkFailures';
        host.style.cssText = 'margin-top:12px;';
        var summary = document.getElementById('subSummary');
        if (summary && summary.parentNode) summary.parentNode.insertBefore(host, summary.nextSibling);
    }
    if (!failed || !failed.length) { host.innerHTML = ''; return; }

    var rows = '';
    failed.forEach(function(f) {
        var nm  = esc(f.name || f.subject || '?');
        var why = esc(f.reason || f.message || 'unknown error');
        rows += '<tr><td style="padding:4px 8px;">' + nm + '</td>'
              + '<td style="padding:4px 8px;color:var(--danger,#c00);">' + why + '</td></tr>';
    });
    host.innerHTML =
        '<div style="border:1px solid var(--danger,#c00);border-radius:6px;padding:10px;background:rgba(192,0,0,0.06);">'
      + '<div style="font-weight:600;margin-bottom:6px;color:var(--danger,#c00);">'
      + '<i class="fa fa-exclamation-triangle"></i> '
      + failed.length + ' subject(s) failed to save'
      + '</div>'
      + '<table style="width:100%;font-size:12px;border-collapse:collapse;">'
      + '<thead><tr style="text-align:left;color:var(--t2);">'
      + '<th style="padding:4px 8px;">Subject</th><th style="padding:4px 8px;">Reason</th>'
      + '</tr></thead>'
      + '<tbody>' + rows + '</tbody></table></div>';
}

// Subjects that were removed in the editor but deliberately KEPT in Firestore.
// Amber, not red: nothing failed, the save refused a destructive step it could
// not prove was safe.
function renderKeptSubjects(kept) {
    var host = document.getElementById('subBulkKept');
    if (!host) {
        host = document.createElement('div');
        host.id = 'subBulkKept';
        host.style.cssText = 'margin-top:12px;';
        var anchorEl = document.getElementById('subBulkFailures') || document.getElementById('subSummary');
        if (anchorEl && anchorEl.parentNode) anchorEl.parentNode.insertBefore(host, anchorEl.nextSibling);
    }
    if (!kept || !kept.length) { host.innerHTML = ''; return; }

    var rows = '';
    kept.forEach(function(k) {
        var nm  = esc(k.name || k.subject_code || '?');
        var why = esc(k.reason || 'not removed');
        rows += '<tr><td style="padding:4px 8px;">' + nm + '</td>'
              + '<td style="padding:4px 8px;color:#b45309;">' + why + '</td></tr>';
    });
    host.innerHTML =
        '<div style="border:1px solid #b45309;border-radius:6px;padding:10px;background:rgba(180,83,9,0.08);">'
      + '<div style="font-weight:600;margin-bottom:6px;color:#b45309;">'
      + '<i class="fa fa-exclamation-triangle"></i> '
      + kept.length + ' subject(s) could not be removed and are still in the list'
      + '</div>'
      + '<table style="width:100%;font-size:12px;border-collapse:collapse;">'
      + '<thead><tr style="text-align:left;color:var(--t2);">'
      + '<th style="padding:4px 8px;">Subject</th><th style="padding:4px 8px;">Why it stayed</th>'
      + '</tr></thead>'
      + '<tbody>' + rows + '</tbody></table></div>';
}

/* Keep old function names working for backward compat */
window.loadSubjects = function() { subLoadClass(); };
window.loadSuggestedSubjects = function() { subResetDefaults(); };
window.addSubject = function() { subAddRow(); };
window.deleteSubject = function(ck, code) {
    post('school_config/delete_subject', { class_key: ck, code: code }, function(d) {
        toast(d.message, d.status === 'success');
        if (d.status === 'success') subLoadClass();
    });
};

/* ══════════ STREAMS ══════════ */
function renderStreams(streams) {
    var el = document.getElementById('streamsList');
    var items = [];
    if (typeof streams === 'object') {
        Object.keys(streams).forEach(function(k) {
            var s = streams[k];
            if (s) items.push(s);
        });
    }

    if (!items.length) {
        el.innerHTML = '<div class="sc-empty"><i class="fa fa-code-fork"></i> No streams configured. Click "Seed Standard Streams" or add manually below.</div>';
        return;
    }

    el.innerHTML = items.map(function(s) {
        var key = s.key || '';
        return '<div style="display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--bg3);border:1px solid var(--border);border-radius:8px;margin-bottom:8px;">'
            + '<span class="sc-tag sc-tag-teal" style="min-width:80px;text-align:center;">' + esc(key) + '</span>'
            + '<span style="font-size:13px;color:var(--t1);font-weight:600;flex:1;">' + esc(s.label || key) + '</span>'
            + '<span class="sc-tag ' + (s.enabled ? 'sc-tag-green' : 'sc-tag-red') + '">' + (s.enabled ? 'Enabled' : 'Disabled') + '</span>'
            + '<button class="sc-btn sc-btn-ghost sc-btn-sm" onclick="editStream(\'' + esc(key) + '\',\'' + esc(s.label) + '\',' + (s.enabled ? 'true' : 'false') + ')">'
            + '<i class="fa fa-pencil"></i></button>'
            + '<button class="sc-btn sc-btn-danger sc-btn-sm" onclick="deleteStream(\'' + esc(key) + '\')">'
            + '<i class="fa fa-trash"></i></button>'
            + '</div>';
    }).join('');
}

/* Populate the subject stream dropdown from configured streams (legacy compat, now a no-op) */
function populateStreamDropdown(streams) {
    // Stream dropdowns are now managed by the v2 subject editor (_subShowEditor)
}

window.editStream = function(key, label, enabled) {
    document.getElementById('newStreamKey').value   = key;
    document.getElementById('newStreamLabel').value = label;
    document.getElementById('newStreamEnabled').checked = enabled;
};

window.saveStream = function() {
    var key   = document.getElementById('newStreamKey').value.trim();
    var label = document.getElementById('newStreamLabel').value.trim();
    var en    = document.getElementById('newStreamEnabled').checked ? '1' : '0';
    if (!key || !label) { toast('Stream key and label are required.', false); return; }
    post('school_config/save_stream', { stream_key: key, label: label, enabled: en }, function(d) {
        toast(d.message, d.status === 'success');
        if (d.status === 'success') {
            document.getElementById('newStreamKey').value = '';
            document.getElementById('newStreamLabel').value = '';
            document.getElementById('newStreamEnabled').checked = true;
            // Use server-returned streams if available, else update local cache
            if (d.streams && typeof d.streams === 'object' && !Array.isArray(d.streams)) {
                CFG.streams = d.streams;
            } else {
                if (!CFG.streams || Array.isArray(CFG.streams)) CFG.streams = {};
                CFG.streams[key] = { key: key, label: label, enabled: en === '1' };
            }
            renderStreams(CFG.streams);
            populateStreamDropdown(CFG.streams);
        }
    });
};

window.deleteStream = function(key) {
    _confirmModal({
        title: 'Delete stream "' + key + '"',
        body: 'Remove this stream from the list?',
        confirmText: 'Delete',
        dangerous: true
    }).then(function(ok) {
        if (!ok) return;

        /* ── PHASE 20.1c (P20-F12) — PHANTOM SUCCESS ────────────────────
         *
         * THE DEFECT THIS CLOSES: this handler deleted the stream from local
         * state and re-rendered BEFORE calling the server, then reported only
         * the success case and swallowed every error ("Don't show error if
         * server says not found").
         *
         * So when the server correctly REFUSED — a 409 because live sections
         * or students still reference the stream, or the Phase 20.1b
         * fail-closed path because the dependency state could not be verified
         * — the operator watched the stream disappear and was told nothing.
         * The entire fail-closed backend was invisible on this path: the
         * server protected the data, and the UI reported the opposite.
         *
         * Now: call the server FIRST, and only mutate local state once it
         * confirms. Failures are surfaced, and a refusal leaves the row
         * exactly where it is. Fail closed in the UI as well as the backend.
         */
        var _busy = deleteStream._busy || (deleteStream._busy = {});
        if (_busy[key]) return;              // duplicate-click protection
        _busy[key] = true;

        post('school_config/delete_stream', { stream_key: key }, function(d) {
            _busy[key] = false;

            if (d && d.status === 'success') {
                if (!CFG.streams || Array.isArray(CFG.streams)) CFG.streams = {};
                delete CFG.streams[key];
                renderStreams(CFG.streams);
                populateStreamDropdown(CFG.streams);
                toast('Stream deleted.');
                return;
            }

            // A stream that was never saved server-side is genuinely absent —
            // that alone may clear locally. Everything else is a real refusal
            // and MUST leave the UI unchanged.
            var msg = (d && d.message) ? String(d.message) : '';
            // The server's not-found path uses plain json_error() and carries
            // no `reason`, so match its message too rather than changing the
            // server's response shape for a purely cosmetic case.
            var notFound = (d && d.reason === 'not_found') || /does not exist/i.test(msg);
            if (notFound) {
                if (!CFG.streams || Array.isArray(CFG.streams)) CFG.streams = {};
                delete CFG.streams[key];
                renderStreams(CFG.streams);
                populateStreamDropdown(CFG.streams);
                return;
            }

            // Distinguish "cannot delete" from "server error" for the operator.
            if (d && d.reason === 'dependencies_undetermined') {
                toast(msg || 'Could not verify what uses this stream. Nothing was changed — please retry.', false);
            } else if (d && d.dependencies) {
                toast(msg || 'Cannot delete: this stream is still in use.', false);
            } else {
                toast(msg || 'Delete failed. Nothing was changed.', false);
            }
        });
    });
};

/* Issue 6: Seed standard streams */
window.seedStandardStreams = function() {
    _confirmModal({
        title: 'Load standard streams',
        body: 'This will load standard streams (Science, Commerce, Arts, General) into the list.\nClick "Save Stream" for each to persist.',
        confirmText: 'Load'
    }).then(function(ok) {
        if (!ok) return;

        var defaults = {
            Science:  { key: 'Science',  label: 'Science',  enabled: true },
            Commerce: { key: 'Commerce', label: 'Commerce', enabled: true },
            Arts:     { key: 'Arts',     label: 'Arts',     enabled: true },
            General:  { key: 'General',  label: 'General',  enabled: true },
        };

        // Merge with existing (don't overwrite)
        if (!CFG.streams || Array.isArray(CFG.streams)) CFG.streams = {};
        var added = 0, skipped = 0;
        Object.keys(defaults).forEach(function(k) {
            if (CFG.streams[k]) { skipped++; return; }
            CFG.streams[k] = defaults[k];
            added++;
        });

        renderStreams(CFG.streams);
        populateStreamDropdown(CFG.streams);
        // Show save bar
        document.getElementById('streamsSaveBar').style.display = added > 0 ? 'block' : 'none';
        toast(added + ' stream(s) loaded. Click "Save All Streams" to persist.');
    });
};

window.saveAllStreams = function() {
    var streams = CFG.streams || {};
    var keys = Object.keys(streams);
    if (!keys.length) { toast('No streams to save.', false); return; }

    // Clear any prior failure panel before retry
    renderBulkStreamFailures([]);

    // 2026-07-23 — bulk save: ONE HTTP request → save_streams (one CAS check,
    // one Firestore commitBatch, all-or-nothing). Replaces the previous N
    // sequential save_stream calls, matching the single-request bulk-save
    // pattern already used by Classes / Sections / Subjects. Individual Add/Edit
    // still uses save_stream().
    var payload = keys.map(function(k) {
        var s = streams[k] || {};
        return { stream_key: (s.key || k), label: (s.label || k), enabled: !!s.enabled };
    });
    var total = payload.length;
    var btn = document.querySelector('#streamsSaveBar button');
    if (btn) { btn.disabled = true; }

    post('school_config/save_streams', { streams: JSON.stringify(payload) }, function(d) {
        if (btn) { btn.disabled = false; }
        if (d && d.status === 'success') {
            // Adopt the server-canonical map so the UI matches exactly what persisted.
            if (d.streams && typeof d.streams === 'object' && !Array.isArray(d.streams)) {
                CFG.streams = d.streams;
                renderStreams(CFG.streams);
                populateStreamDropdown(CFG.streams);
            }
            document.getElementById('streamsSaveBar').style.display = 'none';
            toast(d.message || (total + ' stream(s) saved.'), true);
        } else {
            // Atomic endpoint: on failure NOTHING was written. Keep the save bar
            // visible so retry is one click.
            document.getElementById('streamsSaveBar').style.display = 'block';
            toast((d && d.message) ? d.message : 'Failed to save streams.', false);
        }
    }, { loader: 'Saving streams…' });
};

// 2026-05-26 — partial-failure renderer for saveAllStreams (BUG-067).
// Mirrors renderBulkSectionFailures (line 2464) + renderBulkSubjectFailures (line 2801)
// pattern shipped 2026-05-15 Phase 3. Idempotent: empty array clears prior panel.
function renderBulkStreamFailures(failed) {
    var host = document.getElementById('streamsBulkFailures');
    if (!host) {
        host = document.createElement('div');
        host.id = 'streamsBulkFailures';
        host.style.cssText = 'margin-top:12px;';
        var list = document.getElementById('streamsList');
        if (list && list.parentNode) list.parentNode.insertBefore(host, list.nextSibling);
    }
    if (!failed || !failed.length) { host.innerHTML = ''; return; }

    var rows = '';
    failed.forEach(function(f) {
        var key   = esc(f.stream_key || f.streamKey || '?');
        var label = esc(f.label      || f.name      || '');
        var why   = esc(f.reason     || f.message   || 'unknown error');
        rows += '<tr><td style="padding:4px 8px;">' + key + '</td>'
              + '<td style="padding:4px 8px;color:var(--t3);">' + label + '</td>'
              + '<td style="padding:4px 8px;color:var(--danger,#c00);">' + why + '</td></tr>';
    });
    host.innerHTML =
        '<div style="border:1px solid var(--danger,#c00);border-radius:6px;padding:10px;background:rgba(192,0,0,0.06);">'
      + '<div style="font-weight:600;margin-bottom:6px;color:var(--danger,#c00);">'
      + '<i class="fa fa-exclamation-triangle"></i> '
      + failed.length + ' stream(s) failed to save'
      + '</div>'
      + '<table style="width:100%;font-size:12px;border-collapse:collapse;">'
      + '<thead><tr style="text-align:left;color:var(--t2);">'
      + '<th style="padding:4px 8px;">Key</th><th style="padding:4px 8px;">Label</th><th style="padding:4px 8px;">Reason</th>'
      + '</tr></thead>'
      + '<tbody>' + rows + '</tbody></table></div>';
}

/* ── Escape HTML ─────────────────────────────────────────────── */
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

/* ══════════ REPORT CARD TEMPLATE ══════════ */
// 2026-05-26 (BUG-068) — split selected vs persisted state. Label reflects persisted only;
// tile-click changes the in-progress selection + an orange "Selected: X (click Save to persist)"
// suffix; Save success promotes selected → persisted and clears the suffix.
var selectedRcTemplate  = 'classic';   // current click selection (in-progress)
var persistedRcTemplate = 'classic';   // last server-confirmed value
var _rcNames = { classic:'Classic', cbse:'CBSE', minimal:'Minimal', modern:'Modern', elegant:'Elegant', professional:'Professional' };

function _renderRcState() {
    // Tile .active reflects user's current selection (immediate visual feedback on click).
    var cards = document.querySelectorAll('#rcTemplateGrid .rct-card');
    cards.forEach(function(c) {
        c.classList.toggle('active', c.dataset.tpl === selectedRcTemplate);
    });
    // Label reflects PERSISTED value only — never in-progress selection.
    var label = document.getElementById('rcCurrentLabel');
    if (!label) return;
    var persistedName = _rcNames[persistedRcTemplate] || 'Classic';
    if (selectedRcTemplate !== persistedRcTemplate) {
        var selName = _rcNames[selectedRcTemplate] || selectedRcTemplate;
        label.innerHTML = 'Current: ' + esc(persistedName)
            + ' <span style="color:#d97706;font-weight:600;">· Selected: '
            + esc(selName) + ' (click Save to persist)</span>';
    } else {
        label.textContent = 'Current: ' + persistedName;
    }
}

// Called by loadConfig with the server-persisted value; both selected and persisted move
// to it, no dirty suffix. Public callers (loadConfig @ line ~1054) treat the arg as the
// authoritative persisted template.
function renderReportCardTemplate(persistedTpl) {
    persistedRcTemplate = persistedTpl || 'classic';
    selectedRcTemplate  = persistedRcTemplate;
    _renderRcState();
}

document.getElementById('rcTemplateGrid').addEventListener('click', function(e) {
    var card = e.target.closest('.rct-card');
    if (!card) return;
    // Tile click changes ONLY in-progress selection. Label stays at persisted value
    // (with orange suffix when dirty) until Save success.
    selectedRcTemplate = card.dataset.tpl || 'classic';
    _renderRcState();
});

document.getElementById('btnSaveRcTemplate').addEventListener('click', function() {
    var saving = selectedRcTemplate;
    post('school_config/save_report_card_template', { template: saving }, function(d) {
        CSRFT = (d && d.csrf_token) ? d.csrf_token : CSRFT;
        if (d && d.status === 'success') {
            // Promote selected → persisted; clears the dirty suffix on next render.
            persistedRcTemplate = saving;
            _renderRcState();
            toast('Report card template saved!');
        } else {
            toast((d && d.message) || 'Failed to save template.', false);
        }
    });
});

/* ══════════ REPORT CARD CUSTOMIZATION ══════════ */
var RCC_SECTIONS = ['photo','resultStrip','gradingLegend','coScholastic','attendance','signatures'];
var RCC_TEXT     = ['title','footer','principalName','examControllerName','classTeacherName','defaultRemark'];
var RCC_ASSETS   = ['logoUrl','principalSignUrl','classTeacherSignUrl'];

function _rccDefaults() {
    var s = {}; RCC_SECTIONS.forEach(function(k){ s[k] = true; });
    var t = {}; RCC_TEXT.forEach(function(k){ t[k] = ''; });
    var a = {}; RCC_ASSETS.forEach(function(k){ a[k] = ''; });
    return { accentColor:'#1a3a6b', sections:s, text:t, assets:a };
}
var rccState = _rccDefaults();

function _validHex(v){ return /^#[0-9a-fA-F]{6}$/.test(v); }

// Darken / tint helpers mirror _report_card_data.php
function _shade(hex, factor){
    var r=parseInt(hex.substr(1,2),16), g=parseInt(hex.substr(3,2),16), b=parseInt(hex.substr(5,2),16);
    r=Math.round(r*factor); g=Math.round(g*factor); b=Math.round(b*factor);
    return '#'+[r,g,b].map(function(x){ return ('0'+Math.max(0,Math.min(255,x)).toString(16)).slice(-2); }).join('');
}
function _tint(hex){
    var r=parseInt(hex.substr(1,2),16), g=parseInt(hex.substr(3,2),16), b=parseInt(hex.substr(5,2),16);
    r=Math.round(r+(255-r)*0.92); g=Math.round(g+(255-g)*0.92); b=Math.round(b+(255-b)*0.92);
    return '#'+[r,g,b].map(function(x){ return ('0'+x.toString(16)).slice(-2); }).join('');
}

// Populate the form + state from a server config object (called by loadConfig)
function renderRcConfig(cfg) {
    var def = _rccDefaults();
    cfg = (cfg && typeof cfg === 'object') ? cfg : {};
    rccState.accentColor = _validHex(cfg.accentColor) ? cfg.accentColor : def.accentColor;
    var secIn = cfg.sections || {}, txtIn = cfg.text || {}, asIn = cfg.assets || {};
    RCC_SECTIONS.forEach(function(k){ rccState.sections[k] = (k in secIn) ? !!secIn[k] : true; });
    RCC_TEXT.forEach(function(k){ rccState.text[k] = (txtIn[k] != null) ? String(txtIn[k]) : ''; });
    RCC_ASSETS.forEach(function(k){ rccState.assets[k] = (asIn[k] != null) ? String(asIn[k]) : ''; });

    // Push into inputs
    document.getElementById('rccAccent').value = rccState.accentColor;
    document.getElementById('rccAccentHex').value = rccState.accentColor.toUpperCase();
    RCC_SECTIONS.forEach(function(k){ var el=document.getElementById('rccSec_'+k); if(el) el.checked = rccState.sections[k]; });
    RCC_TEXT.forEach(function(k){ var el=document.getElementById('rccTxt_'+k); if(el) el.value = rccState.text[k]; });
    RCC_ASSETS.forEach(_renderAssetThumb);
    _syncSwatch();
    renderRcPreview();
}

function _renderAssetThumb(slot) {
    var box = document.getElementById('rccAsset_'+slot);
    if (!box) return;
    var url = rccState.assets[slot];
    box.innerHTML = url ? '<img src="'+esc(url)+'" alt="">' : '<span class="rcc-asset-ph">No image</span>';
}

function _syncSwatch() {
    document.querySelectorAll('#rccSwatches .rcc-sw').forEach(function(b){
        b.classList.toggle('active', (b.dataset.c||'').toLowerCase() === rccState.accentColor.toLowerCase());
    });
}

// Pull current form values into rccState (sections + text + accent)
function _collectRcForm() {
    var hex = document.getElementById('rccAccentHex').value.trim();
    if (_validHex(hex)) rccState.accentColor = hex;
    RCC_SECTIONS.forEach(function(k){ var el=document.getElementById('rccSec_'+k); if(el) rccState.sections[k]=el.checked; });
    RCC_TEXT.forEach(function(k){ var el=document.getElementById('rccTxt_'+k); if(el) rccState.text[k]=el.value; });
}

/* ── Live mini preview ── */
function renderRcPreview() {
    _collectRcForm();
    var c = rccState, ac = c.accentColor, dark = _shade(ac,0.72), tint = _tint(ac);
    var s = c.sections, t = c.text, a = c.assets;
    var logo = a.logoUrl
        ? '<span class="rcp-logo"><img src="'+esc(a.logoUrl)+'"></span>'
        : '<span class="rcp-logo">SC</span>';
    var rows = [
        ['English','95 / 100','95%','A1'],
        ['Mathematics','88 / 100','88%','A2'],
        ['Science','76 / 100','76%','B1']
    ].map(function(r){ return '<tr><td class="l">'+r[0]+'</td><td>'+r[1]+'</td><td>'+r[2]+'</td><td>'+r[3]+'</td></tr>'; }).join('');

    var html = ''
      + '<div class="rcp" style="--rcp-accent:'+esc(ac)+';--rcp-dark:'+esc(dark)+';--rcp-tint:'+esc(tint)+'">'
      +   '<div class="rcp-head">'+logo+'<div class="rcp-head-c"><div class="rcp-school">YOUR SCHOOL NAME</div><div class="rcp-sub">123 Education Road, City &nbsp;|&nbsp; Affiliated to CBSE</div></div></div>'
      +   '<div class="rcp-title">'+esc(t.title || 'Academic Report Card')+'</div>'
      +   '<div class="rcp-stu"><div class="rcp-stu-grid">'
      +     '<div><span>Name:</span> <b>RAHUL SHARMA</b></div><div><span>Roll:</span> STU0001</div>'
      +     '<div><span>Class:</span> 9th - A</div><div><span>DOB:</span> 12 Aug 2011</div>'
      +   '</div>'+(s.photo ? '<div class="rcp-photo">Photo</div>' : '')+'</div>'
      +   '<div class="rcp-seclbl">Scholastic Performance</div>'
      +   '<table class="rcp-tbl"><thead><tr><th style="text-align:left">Subject</th><th>Total</th><th>%</th><th>Grade</th></tr></thead><tbody>'+rows+'</tbody></table>'
      +   (s.resultStrip ? '<div class="rcp-strip"><div><b>259</b><small>Total</small></div><div><b>86.3%</b><small>Percent</small></div><div><b style="color:'+esc(ac)+'">A2</b><small>Grade</small></div><div><b>3</b><small>Rank</small></div><div><b style="color:#15803d">Pass</b><small>Result</small></div></div>' : '')
      +   (s.gradingLegend ? '<div class="rcp-legend"><b>Grading Scale:</b> A1=(91-100), A2=(81-90), B1=(71-80)...</div>' : '')
      +   (s.coScholastic ? '<div class="rcp-seclbl">Co-Scholastic Areas</div><div class="rcp-cosch"><div>Art Education<b>—</b></div><div>Health &amp; PE<b>—</b></div><div>Work Education<b>—</b></div><div>Discipline<b>—</b></div></div>' : '')
      +   (s.attendance ? '<div class="rcp-seclbl">Attendance &amp; Remarks</div><div class="rcp-att"><div><span>Working Days</span>—</div><div><span>Present</span>—</div><div><span>Remark</span>'+esc(t.defaultRemark||'—')+'</div></div>' : '')
      +   (s.signatures ? '<div class="rcp-sigs">'
            + '<div class="rcp-sig">'+(a.classTeacherSignUrl?'<img src="'+esc(a.classTeacherSignUrl)+'">':'')+'<div class="ln"></div><div class="nm">'+esc(t.classTeacherName||'Class Teacher')+'</div></div>'
            + '<div class="rcp-sig"><div class="ln"></div><div class="nm">'+esc(t.examControllerName||'Exam Controller')+'</div></div>'
            + '<div class="rcp-sig">'+(a.principalSignUrl?'<img src="'+esc(a.principalSignUrl)+'">':'')+'<div class="ln"></div><div class="nm">'+esc(t.principalName||'Principal')+'</div></div>'
          + '</div>' : '')
      +   (t.footer !== '' ? '<div class="rcp-foot">'+esc(t.footer)+'</div>' : '<div class="rcp-foot">This is a computer-generated report card.</div>')
      + '</div>';
    document.getElementById('rccPreview').innerHTML = html;
}

/* ── Input wiring ── */
(function () {
    var accent = document.getElementById('rccAccent');
    var hex    = document.getElementById('rccAccentHex');
    if (accent) accent.addEventListener('input', function(){ hex.value = this.value.toUpperCase(); _syncSwatch(); renderRcPreview(); });
    if (hex) hex.addEventListener('input', function(){
        var v = this.value.trim();
        if (_validHex(v)) { accent.value = v; _syncSwatch(); renderRcPreview(); }
    });
    document.querySelectorAll('#rccSwatches .rcc-sw').forEach(function(b){
        b.addEventListener('click', function(){
            var c = this.dataset.c; accent.value = c; hex.value = c.toUpperCase();
            rccState.accentColor = c; _syncSwatch(); renderRcPreview();
        });
    });
    RCC_SECTIONS.forEach(function(k){ var el=document.getElementById('rccSec_'+k); if(el) el.addEventListener('change', renderRcPreview); });
    RCC_TEXT.forEach(function(k){ var el=document.getElementById('rccTxt_'+k); if(el) el.addEventListener('input', renderRcPreview); });

    // Asset uploads
    document.querySelectorAll('.rcc-file').forEach(function(inp){
        inp.addEventListener('change', function(){
            if (!this.files.length) return;
            var slot = this.dataset.slot, file = this.files[0], self = this;
            var box = document.getElementById('rccAsset_'+slot);
            box.innerHTML = '<span class="rcc-asset-ph">Uploading…</span>';
            var fd = new FormData();
            fd.append('asset', file); fd.append('slot', slot); fd.append(CSRFN, CSRFT);
            if (window.ZXLoader) ZXLoader.show('Uploading image…');
            fetch(BASE + 'school_config/upload_reportcard_asset', { method:'POST', headers:{ 'X-CSRF-Token':CSRFT }, body:fd })
            .then(function(r){ return r.json(); })
            .then(function(d){
                if (d.csrf_token) CSRFT = d.csrf_token;
                if (d.status === 'success') {
                    rccState.assets[slot] = d.url; _renderAssetThumb(slot); renderRcPreview();
                    toast('Image uploaded!');
                } else { _renderAssetThumb(slot); toast(d.message || 'Upload failed.', false); }
                self.value = '';
            })
            .catch(function(){ _renderAssetThumb(slot); toast('Upload failed.', false); self.value=''; })
            .finally(function(){ if (window.ZXLoader) ZXLoader.hide(); });
        });
    });
    // Asset remove
    document.querySelectorAll('.rcc-mini-rm').forEach(function(btn){
        btn.addEventListener('click', function(){
            var slot = this.dataset.slot;
            if (!rccState.assets[slot]) return;
            post('school_config/remove_reportcard_asset', { slot:slot }, function(d){
                if (d.status === 'success') { rccState.assets[slot]=''; _renderAssetThumb(slot); renderRcPreview(); toast('Image removed.'); }
                else { toast(d.message || 'Failed to remove.', false); }
            });
        });
    });

    var saveBtn = document.getElementById('btnSaveRcConfig');
    if (saveBtn) saveBtn.addEventListener('click', function(){
        _collectRcForm();
        if (!_validHex(rccState.accentColor)) { toast('Enter a valid hex color (e.g. #1a3a6b).', false); return; }
        var payload = { accentColor:rccState.accentColor, sections:rccState.sections, text:rccState.text };
        var msg = document.getElementById('rccSaveMsg'); msg.textContent = 'Saving…';
        post('school_config/save_report_card_config', { config: JSON.stringify(payload) }, function(d){
            if (d.status === 'success') { msg.textContent = 'Saved'; toast('Customization saved!'); setTimeout(function(){ msg.textContent=''; }, 2500); }
            else { msg.textContent = ''; toast(d.message || 'Failed to save.', false); }
        });
    });

    var resetBtn = document.getElementById('btnResetRcConfig');
    if (resetBtn) resetBtn.addEventListener('click', function(){
        var keptAssets = rccState.assets;          // uploads are managed separately; keep them
        rccState = _rccDefaults();
        rccState.assets = keptAssets;
        renderRcConfig(rccState);
        toast('Reset to defaults — click Save Customization to persist.');
    });
})();

/* ── Expose to inline onclick handlers ────────────────────────── */
window.saveProfile = saveProfile;
window.saveForgetPwd = saveForgetPwd;
window.syncSessions = syncSessions;
window.addSession = addSession;
window.setActive = setActive;
window.suggestNextSession = suggestNextSession;
window.checkSessions = checkSessions;
window.archiveSession = archiveSession;
window.deleteSession = deleteSession;
window.openRolloverModal = openRolloverModal;
window.closeRolloverModal = closeRolloverModal;
window.rollPreview = rollPreview;
window.rollExecute = rollExecute;
window.saveBoard = saveBoard;
window.addGradeRow = addGradeRow;
window.addClassRow = addClassRow;
window.saveClasses = saveClasses;
window.seedDefaultClasses = seedDefaultClasses;
window.activateClassesInSession = activateClassesInSession;
window.softDeleteClass = softDeleteClass;
window.restoreClass = restoreClass;
/* subject functions are assigned to window inline in their definitions */

/* ── Init ────────────────────────────────────────────────────── */
loadConfig();

}());
</script>
