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
.sc-tab-num {
    display:inline-flex; align-items:center; justify-content:center;
    width:18px; height:18px; border-radius:50%; font-size:10px; font-weight:700;
    background:var(--gold-dim); color:var(--gold); line-height:1;
}
.sc-tab.active .sc-tab-num { background:rgba(255,255,255,.25); color:#fff; }

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
                    <label>City</label>
                    <input type="text" id="pf_city" maxlength="60" placeholder="City">
                </div>
                <div class="sc-field">
                    <label>State</label>
                    <input type="text" id="pf_state" maxlength="60" placeholder="State">
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
            <div class="sc-grid sc-grid-2">
                <div class="sc-field">
                    <label>Holidays Calendar</label>
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
                    <label>Academic Calendar</label>
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
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;"><input type="checkbox" id="rollPromote"> Promote students (Class N &rarr; N+1, Class 12 &rarr; Alumni)</label>
                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;"><input type="checkbox" id="rollSetActive"> Set target session as ACTIVE after rollover</label>
                    </div>
                    <div id="rollPromoteWarn" style="display:none;background:rgba(217,119,6,.12);border:1px solid rgba(217,119,6,.45);border-radius:8px;padding:10px;font-size:12px;color:#92400e;margin-bottom:12px;">
                        <b>Heads up:</b> Student promotion updates each student's <code>session</code> and <code>className</code> in place. After rollover the source session will show NO active students (historical marks/attendance/fees remain intact with their original session stamp). Class 12 students become <code>status=Alumni</code>.
                    </div>
                    <div style="display:flex;justify-content:flex-end;gap:8px;">
                        <button class="sc-btn sc-btn-ghost" onclick="closeRolloverModal()">Cancel</button>
                        <button class="sc-btn sc-btn-primary" id="rollExecBtn" onclick="rollExecute()"><i class="fa fa-play"></i> Execute Rollover</button>
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
                <div id="sectionMatrixLoader" style="text-align:center;padding:30px 0;color:var(--t3);">
                    <i class="fa fa-spinner fa-spin"></i> Loading sections...
                </div>
                <div id="sectionMatrix"></div>
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
            </div>

            <div style="margin-top:18px;display:flex;align-items:center;gap:14px;">
                <button class="sc-btn sc-btn-gold" id="btnSaveRcTemplate">
                    <i class="fa fa-save"></i> Save Template
                </button>
                <span id="rcCurrentLabel" style="font-size:12px;color:var(--t3);">Current: Classic</span>
            </div>
        </div>
    </div>

</div><!-- /.sc-wrap -->
</div><!-- /.content-wrapper -->

<div id="scToast"></div>

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
        syncSessions();
    }
    if (btn.dataset.tab === 'sections') {
        loadAllSections();
    }
});

/* ── POST helper ────────────────────────────────────────────── */
function _readCsrfCookie() {
    var match = document.cookie.match(new RegExp('(?:^|;\\s*)' + CSRFN + '=([^;]+)'));
    return match ? decodeURIComponent(match[1]) : CSRFT;
}
function post(url, data, cb) {
    // Always read the latest CSRF token from the cookie to avoid stale-token errors
    // (e.g., when switching between SA panel and school panel tabs)
    CSRFT = _readCsrfCookie();
    data[CSRFN] = CSRFT;
    var body = Object.keys(data).map(function(k) {
        return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
    }).join('&');

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
    .then(function(d) { if (d.csrf_token) CSRFT = d.csrf_token; cb(d); })
    .catch(function(e) {
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
        renderSessions(d.sessions || [], d.active_session || '');
        renderBoard(d.board || {});
        renderClasses(d.classes || []);
        renderClassSelects(d.classes || [], d.sessions || [], d.active_session || '');
        var streams = (d.streams && typeof d.streams === 'object' && !Array.isArray(d.streams)) ? d.streams : {};
        CFG.streams = streams;
        renderStreams(streams);
        populateStreamDropdown(streams);
        renderReportCardTemplate(d.report_card_template || 'classic');
    });
}

/* ══════════ PROFILE ══════════ */
function renderProfile(p) {
    var fields = ['display_name','principal_name','established_year','affiliation_board',
                  'affiliation_no','address','city','state','pincode','phone','email','website'];
    fields.forEach(function(f) {
        var el = document.getElementById('pf_' + f);
        if (el) el.value = p[f] || '';
    });
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
    });
}

/* Logo upload */
document.getElementById('logoFile').addEventListener('change', function() {
    if (!this.files.length) return;
    var fd = new FormData();
    fd.append('logo', this.files[0]);
    fd.append(CSRFN, CSRFT);
    document.getElementById('logoMsg').textContent = 'Uploading...';
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
    .catch(function() { toast('Upload failed.', false); });
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
    msg.textContent = 'Uploading...';
    msg.style.color = 'var(--t3)';
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
    .catch(function() { msg.textContent = 'Upload failed.'; toast('Upload failed.', false); });
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
    post('school_config/session_stats', {}, function(d) {
        loadSessionStats._inflight = false;
        if (d.status !== 'success' || !Array.isArray(d.stats)) return;
        _sessStats = {};
        d.stats.forEach(function(r) { _sessStats[r.session] = r; });
        // Re-render with stats now populated.
        renderSessionsOnly(CFG.sessions || sessions, CFG.active_session || '');
    });
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
                 + '<li>' + (d.graduating || 0) + ' student(s) in Class 12 (would graduate if promoted)</li>'
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
    if (prom) warn += '  \u2022 PROMOTE students (Class N \u2192 N+1, Class 12 \u2192 Alumni)\n';
    if (act)  warn += '  \u2022 Set ' + to + ' as the ACTIVE session\n';
    warn += '\nThis cannot be automatically reversed. Proceed?';
    if (!confirm(warn)) return;

    var btn = document.getElementById('rollExecBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-refresh sc-spin"></i> Rolling over...';

    post('school_config/rollover_session', {
        from_session: from, to_session: to,
        copy_sections: copy, promote_students: prom, set_active: act,
    }, function(d) {
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-play"></i> Execute Rollover';
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
            closeRolloverModal();
            if (d.summary && d.summary.errors && d.summary.errors.length) {
                alert('Rollover finished with ' + d.summary.errors.length + ' error(s):\n\n' + d.summary.errors.slice(0, 5).join('\n'));
            }
        }
    });
};

window.archiveSession = function(sess, doArchive) {
    var verb = doArchive ? 'archive' : 'unarchive';
    if (doArchive && !confirm('Archive "' + sess + '"?\n\nIt will be hidden from default dropdowns but all data (students, marks, fees, attendance) is preserved. You can unarchive later.')) return;
    post('school_config/archive_session', { session: sess, archive: doArchive ? 1 : 0 }, function(d) {
        toast(d.message, d.status === 'success');
        if (d.status === 'success') {
            CFG.archived_sessions = d.archived_sessions || [];
            renderSessionsOnly(CFG.sessions || [], CFG.active_session || '');
        }
    });
};

window.deleteSession = function(sess) {
    var st = _sessStats[sess] || {};
    if (st.students || st.sections || st.staff) {
        alert('Cannot delete "' + sess + '".\n\nIt still has:\n'
            + '  • ' + (st.students || 0) + ' students\n'
            + '  • ' + (st.classes  || 0) + ' classes / ' + (st.sections || 0) + ' sections\n'
            + '  • ' + (st.staff    || 0) + ' staff\n\n'
            + 'Delete or move that data first, or use Archive to keep it but hide the session.');
        return;
    }
    if (!confirm('Delete session "' + sess + '" permanently?\n\nThis only removes it from the sessions list. The server will also refuse if any data is still linked to this session.')) return;
    post('school_config/delete_session', { session: sess }, function(d) {
        toast(d.message, d.status === 'success');
        if (d.status === 'success') {
            CFG.sessions = d.sessions || [];
            renderSessions(CFG.sessions, CFG.active_session || '');
            renderClassSelects(CFG.classes || [], CFG.sessions, CFG.active_session || '');
        }
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
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-refresh sc-spin"></i> Checking...'; }
    var box = document.getElementById('sessCheckResult');
    post('school_config/check_sessions', {}, function(d) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-stethoscope"></i> Consistency Check'; }
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

window.syncSessions = function() {
    var btn = document.getElementById('syncSessBtn');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-refresh sc-spin"></i> Syncing...'; }

    post('school_config/sync_sessions', {}, function(d) {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-refresh"></i> Sync from Firebase'; }

        if (d.status === 'success') {
            CFG.sessions = d.sessions || [];
            if (d.active_session) CFG.active_session = d.active_session;
            if (d.archived_sessions) CFG.archived_sessions = d.archived_sessions;
            var act = CFG.active_session || d.active_session || '';
            renderSessions(d.sessions || [], act);
            renderClassSelects(CFG.classes || [], d.sessions || [], act);
            toast(d.message || 'Sessions refreshed from Firebase.');
        } else {
            toast(d.message || 'Sync failed.', false);
        }
    });
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

            // Add the new session to the header dropdown so it can be switched to
            var headerList = document.querySelector('.g-sess-list');
            if (headerList) {
                var existing = headerList.querySelector('[data-year="' + val + '"]');
                if (!existing) {
                    var li = document.createElement('li');
                    li.className = 'g-sess-item';
                    li.dataset.year = val;
                    li.innerHTML = '<i class="fa fa-check g-sess-check"></i> ' + val;
                    li.addEventListener('click', function() {
                        var year = this.dataset.year;
                        $.post(BASE_URL + 'admin/switch_session', { session_year: year })
                         .done(function(res) { if (res && res.status === 'success') window.location.reload(); })
                         .fail(function() { alert('Failed to switch session.'); });
                    });
                    headerList.appendChild(li);
                }
            }
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
    if (!confirm(warn)) return;
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
            // Update header dropdown active state
            document.querySelectorAll('.g-sess-item').forEach(function(item) {
                if (item.dataset.year === sess) {
                    item.classList.add('g-sess-item--active');
                } else {
                    item.classList.remove('g-sess-item--active');
                }
            });
        }
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
    if (!confirm('This will replace the current class list with the standard list (1-12 + Foundational). Continue?')) return;
    CFG.classes = DEFAULT_CLASSES.slice();
    renderClasses(CFG.classes);
    toast('Standard classes loaded. Click "Save Class List" to persist.');
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
    if (!confirm('Remove this class from the list?')) return;

    // Remove from local CFG immediately
    CFG.classes = (CFG.classes || []).filter(function(c) { return c.key !== key; });
    renderClasses(CFG.classes);
    renderClassSelects(CFG.classes, CFG.sessions || [], CFG.active_session || '');

    // Also delete from server (best-effort — may not exist if not yet saved)
    post('school_config/soft_delete_class', { class_key: key }, function(d) {
        if (d.status === 'success') {
            toast('Class removed.');
        }
        // Don't show error if server says "not found" — class may have been UI-only
    });
};

window.restoreClass = function(key) {
    if (!confirm('Restore this class?')) return;
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
};

/* Issue 5: Activate classes in the current session */
window.activateClassesInSession = function() {
    var sess = CFG.active_session || currentSession;
    if (!sess) { toast('No active session. Set one first.', false); return; }
    if (!confirm('Create class nodes in session "' + sess + '" for all saved (non-deleted) classes?\n\nExisting class data will NOT be overwritten.')) return;

    post('school_config/activate_classes', { session: sess }, function(d) {
        toast(d.message, d.status === 'success');
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

var _availableStreams = []; // from server

function loadAllSections() {
    var sess = document.getElementById('secSessSel').value;
    if (!sess) { toast('Select a session first.', false); return; }
    document.getElementById('sectionMatrixLoader').style.display = 'block';
    document.getElementById('sectionMatrix').innerHTML = '';
    document.getElementById('secBulkBar').style.display = 'none';

    post('school_config/get_all_sections', { session: sess }, function(d) {
        document.getElementById('sectionMatrixLoader').style.display = 'none';
        if (d.status !== 'success') { toast(d.message, false); return; }
        _secOriginal = {};
        _secCurrent  = {};
        _availableStreams = d.streams || [];

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
            html += '<td style="text-align:center;white-space:nowrap;">'
                + '<button class="sc-btn sc-btn-ghost" style="padding:2px 6px;font-size:10px;border-radius:4px;" '
                + 'onclick="quickSetSections(\'' + esc(cls.key) + '\', 1)" title="Set only Section A">1</button>'
                + '<button class="sc-btn sc-btn-ghost" style="padding:2px 6px;font-size:10px;border-radius:4px;" '
                + 'onclick="quickSetSections(\'' + esc(cls.key) + '\', 3)" title="Set A, B, C">3</button>'
                + '<button class="sc-btn sc-btn-ghost" style="padding:2px 6px;font-size:10px;border-radius:4px;" '
                + 'onclick="quickSetSections(\'' + esc(cls.key) + '\', 6)" title="Set A through F">6</button>'
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

    document.getElementById('sectionMatrix').innerHTML = html;
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
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

    post('school_config/bulk_save_sections', { session: sess, changes: JSON.stringify(changes) }, function(d) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-check"></i> Save All Changes';
        toast(d.message, d.status === 'success');
        if (d.status === 'success') {
            loadAllSections(); // refresh from server
        }
    });
};

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
    if (!confirm('Delete Section ' + letter + '? This cannot be undone.')) return;
    post('school_config/delete_section', { class_key: classKey, section: letter, session: sess }, function(d) {
        toast(d.message, d.status === 'success');
        if (d.status === 'success') loadAllSections();
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
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...';

    post('school_config/save_bulk_subjects', {
        class_key: _subClassKey,
        subjects: JSON.stringify(valid)
    }, function(d) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-save"></i> Save All Subjects';
        toast(d.message, d.status === 'success');
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
    if (!confirm('Delete stream "' + key + '"?')) return;

    // Remove from local UI immediately
    if (!CFG.streams || Array.isArray(CFG.streams)) CFG.streams = {};
    delete CFG.streams[key];
    renderStreams(CFG.streams);
    populateStreamDropdown(CFG.streams);

    // Also delete from server (best-effort — may not exist if not yet saved)
    post('school_config/delete_stream', { stream_key: key }, function(d) {
        if (d.status === 'success') {
            toast('Stream deleted.');
        }
        // Don't show error if server says "not found" — stream may have been UI-only
    });
};

/* Issue 6: Seed standard streams */
window.seedStandardStreams = function() {
    if (!confirm('This will load standard streams (Science, Commerce, Arts, General) into the list.\nClick "Save Stream" for each to persist.')) return;

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
};

window.saveAllStreams = function() {
    var streams = CFG.streams || {};
    var keys = Object.keys(streams);
    if (!keys.length) { toast('No streams to save.', false); return; }

    var saved = 0, total = keys.length;
    var btn = document.querySelector('#streamsSaveBar button');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving...'; }

    // Save each stream via the existing save_stream endpoint
    function saveNext(i) {
        if (i >= keys.length) {
            if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-save"></i> Save All Streams'; }
            document.getElementById('streamsSaveBar').style.display = 'none';
            toast(saved + ' of ' + total + ' streams saved.');
            return;
        }
        var k = keys[i];
        var s = streams[k];
        post('school_config/save_stream', {
            stream_key: s.key || k,
            label: s.label || k,
            enabled: s.enabled ? '1' : '0'
        }, function(d) {
            if (d.status === 'success') saved++;
            saveNext(i + 1);
        });
    }
    saveNext(0);
};

/* ── Escape HTML ─────────────────────────────────────────────── */
function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;');
}

/* ══════════ REPORT CARD TEMPLATE ══════════ */
var selectedRcTemplate = 'classic';

function renderReportCardTemplate(tpl) {
    selectedRcTemplate = tpl || 'classic';
    var cards = document.querySelectorAll('#rcTemplateGrid .rct-card');
    cards.forEach(function(c) {
        c.classList.toggle('active', c.dataset.tpl === selectedRcTemplate);
    });
    var names = { classic:'Classic', cbse:'CBSE', minimal:'Minimal', modern:'Modern', elegant:'Elegant' };
    document.getElementById('rcCurrentLabel').textContent = 'Current: ' + (names[selectedRcTemplate] || 'Classic');
}

document.getElementById('rcTemplateGrid').addEventListener('click', function(e) {
    var card = e.target.closest('.rct-card');
    if (!card) return;
    renderReportCardTemplate(card.dataset.tpl);
});

document.getElementById('btnSaveRcTemplate').addEventListener('click', function() {
    post('school_config/save_report_card_template', { template: selectedRcTemplate }, function(d) {
        CSRFT = d.csrf_token || CSRFT;
        if (d.status === 'success') {
            toast('Report card template saved!');
        } else {
            toast(d.message || 'Failed to save template.', false);
        }
    });
});

/* ── Expose to inline onclick handlers ────────────────────────── */
window.saveProfile = saveProfile;
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
