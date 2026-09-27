<?php defined('BASEPATH') or exit('No direct script access allowed');

$t = $teacher ?? [];

// Helpers
function tp_val($v) { return is_string($v) && $v !== '' ? htmlspecialchars($v, ENT_QUOTES, 'UTF-8') : '<span class="tp-na">N/A</span>'; }
function tp_money($v) { return (is_numeric($v) && $v > 0) ? '₹' . number_format((float)$v, 2) : '<span class="tp-na">N/A</span>'; }

$name       = htmlspecialchars($t['Name'] ?? 'Unknown', ENT_QUOTES, 'UTF-8');
$tid        = htmlspecialchars($t['User ID'] ?? '', ENT_QUOTES, 'UTF-8');
$position   = htmlspecialchars($t['Position'] ?? '', ENT_QUOTES, 'UTF-8');
$dept       = htmlspecialchars($t['Department'] ?? $t['department'] ?? '', ENT_QUOTES, 'UTF-8');
$gender     = $t['Gender'] ?? '';
// Resolve photo: new structure Doc.Photo.url → fallback top-level ProfilePic → legacy Doc.ProfilePic
$_picRaw = '';
if (!empty($t['Doc']['Photo']['url']))    $_picRaw = $t['Doc']['Photo']['url'];
elseif (!empty($t['ProfilePic']))         $_picRaw = $t['ProfilePic'];
elseif (!empty($t['Doc']['ProfilePic']))  $_picRaw = $t['Doc']['ProfilePic'];
$picUrl = htmlspecialchars($_picRaw, ENT_QUOTES, 'UTF-8');
$joining    = $t['Date Of Joining'] ?? '';
$experience = $t['qualificationDetails']['experience'] ?? '';
$defaultPic = base_url('tools/image/default-school.jpeg');
?>

<style>
/* ── Teacher Profile — ERP Gold Theme ── */

:root, [data-theme="night"], [data-theme="day"] {
    --tp-bg:     var(--bg);
    --tp-card:   var(--bg2);
    --tp-border: var(--border);
    --tp-t1:     var(--t1);
    --tp-t2:     var(--t2);
    --tp-t3:     var(--t3);
    --tp-gold:   var(--gold);
    --tp-dim:    var(--gold-dim);
    --tp-sh:     var(--sh);
    --tp-r:      14px;
}

.tp-wrap {
    font-family: var(--font-b);
    background: var(--tp-bg);
    color: var(--tp-t1);
    padding: 20px 20px 52px;
    min-height: 100vh;
}

/* ── Breadcrumb ── */
.tp-breadcrumb {
    display: flex; align-items: center; gap: 6px;
    font-size: 12px; color: var(--tp-t3);
    font-family: var(--font-b); margin-bottom: 20px;
    list-style: none; padding: 0;
}
.tp-breadcrumb li:not(:last-child)::after { content: '/'; margin-left: 6px; opacity: .5; }
.tp-breadcrumb a { color: var(--tp-gold); text-decoration: none; }
.tp-breadcrumb a:hover { text-decoration: underline; }

/* ══════════════════════════════════════════
   HERO
══════════════════════════════════════════ */
.tp-hero {
    background: linear-gradient(130deg, var(--bg4, #0c1e38) 0%, var(--bg, #070f1c) 100%);
    border-radius: var(--tp-r);
    border: 1px solid rgba(188,90,60,.20);
    box-shadow: var(--tp-sh);
    margin-bottom: 4px;
    position: relative;
    overflow: hidden;
}
.tp-hero::before {
    content: '';
    position: absolute; top: 0; left: 0; right: 0; height: 3px;
    background: linear-gradient(90deg, var(--tp-gold), var(--gold2, #9E4830));
}
.tp-hero-inner {
    display: flex;
    align-items: center;
    gap: 28px;
    padding: 28px 32px;
}

/* Avatar */
.tp-avatar-wrap { position: relative; flex-shrink: 0; }
.tp-avatar {
    width: 108px; height: 108px;
    border-radius: 50%;
    object-fit: cover;
    border: 3px solid rgba(188,90,60,.5);
    box-shadow: 0 0 0 5px rgba(188,90,60,.12);
    background: var(--bg3, #0c1e38);
}
.tp-avatar-badge {
    position: absolute; bottom: 4px; right: 4px;
    width: 26px; height: 26px;
    background: linear-gradient(135deg, var(--tp-gold), var(--gold2, #9E4830));
    color: #ffffff;
    border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 12px; font-weight: 700;
    border: 2px solid var(--bg, #070f1c);
}

/* Identity */
.tp-hero-info { flex: 1; min-width: 0; }
.tp-hero-name {
    font-family: var(--font-b);
    font-size: 22px; font-weight: 700;
    color: var(--tp-gold);
    letter-spacing: -.2px;
    margin-bottom: 10px;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.tp-hero-meta { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
.tp-chip {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 4px 12px;
    background: rgba(188,90,60,.15);
    border: 1px solid rgba(188,90,60,.30);
    border-radius: 20px;
    font-size: 12px; color: var(--tp-t1);
    font-family: var(--font-b);
}
.tp-chip--mono { font-family: var(--font-m); }
.tp-chip i { color: var(--tp-gold); font-size: 11px; }
.tp-chip--inactive { background: rgba(197,60,60,.12); border-color: rgba(197,60,60,.30); color: #c53c3c; }
.tp-chip--inactive i { color: #c53c3c; }
.tp-rehire-btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 5px 14px; border-radius: 20px; border: none;
    background: var(--tp-gold); color: #fff; font-size: 12px; font-weight: 700;
    font-family: var(--font-b); cursor: pointer;
}
.tp-rehire-btn:hover { opacity: .88; }

/* ══════════════════════════════════════════
   REHIRE MODAL
══════════════════════════════════════════ */
.tp-modal-overlay {
    display: none; position: fixed; inset: 0; background: rgba(20,14,10,.55);
    z-index: 1000; align-items: center; justify-content: center; padding: 20px;
}
.tp-modal-overlay.tp-modal-open { display: flex; }
.tp-modal {
    background: var(--tp-card); border-radius: var(--tp-r); border: 1px solid var(--tp-border);
    width: 100%; max-width: 480px; max-height: 90vh; overflow-y: auto;
    box-shadow: 0 20px 60px rgba(0,0,0,.3);
}
.tp-modal-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 20px; border-bottom: 1px solid var(--tp-border);
}
.tp-modal-head h4 { margin: 0; font-size: 15px; color: var(--tp-t1); font-family: var(--font-b); }
.tp-modal-close { background: none; border: none; font-size: 18px; color: var(--tp-t3); cursor: pointer; }
.tp-modal-body { padding: 18px 20px; display: flex; flex-direction: column; gap: 14px; }
.tp-modal-field label { display: block; font-size: 11px; text-transform: uppercase; letter-spacing: .04em; color: var(--tp-t3); margin-bottom: 5px; }
.tp-modal-field input[type="text"], .tp-modal-field input[type="date"], .tp-modal-field select, .tp-modal-field textarea {
    width: 100%; padding: 8px 10px; border: 1px solid var(--tp-border); border-radius: 8px;
    font-size: 13px; color: var(--tp-t1); background: #fff; font-family: var(--font-b);
}
.tp-modal-hint { font-size: 11px; color: var(--tp-t3); margin-top: 4px; }
.tp-modal-foot { padding: 14px 20px; border-top: 1px solid var(--tp-border); display: flex; justify-content: flex-end; gap: 10px; }
.tp-modal-btn { padding: 8px 18px; border-radius: 8px; border: none; font-size: 13px; font-weight: 700; cursor: pointer; font-family: var(--font-b); }
.tp-modal-btn--cancel { background: var(--tp-dim); color: var(--tp-t2); }
.tp-modal-btn--submit { background: var(--tp-gold); color: #fff; }
.tp-modal-error { display: none; background: rgba(197,60,60,.1); color: #c53c3c; border-radius: 8px; padding: 8px 12px; font-size: 12px; }

/* Stats strip */
.tp-stats {
    display: flex; align-items: center; gap: 0;
    background: rgba(188,90,60,.08);
    border: 1px solid rgba(188,90,60,.18);
    border-radius: 10px;
    padding: 10px 16px;
    width: fit-content;
}
.tp-stat { text-align: center; padding: 0 16px; }
.tp-stat-val {
    font-family: var(--font-m);
    font-size: 13px; font-weight: 700;
    color: var(--tp-gold);
    white-space: nowrap;
}
.tp-stat-lbl {
    font-size: 10px; color: var(--tp-t3);
    text-transform: uppercase; letter-spacing: .6px;
    margin-top: 2px;
}
.tp-stat-div { width: 1px; height: 32px; background: rgba(188,90,60,.25); }

/* ══════════════════════════════════════════
   TAB BAR
══════════════════════════════════════════ */
.tp-tabs {
    display: flex;
    gap: 4px;
    background: var(--tp-card);
    border: 1px solid var(--tp-border);
    border-radius: var(--tp-r);
    padding: 6px;
    margin-bottom: 4px;
    overflow-x: auto;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
    box-shadow: var(--tp-sh);
}
.tp-tabs::-webkit-scrollbar { display: none; }
.tp-tab {
    flex-shrink: 0;
    display: inline-flex; align-items: center; gap: 6px;
    padding: 8px 16px;
    border: none;
    border-radius: 9px;
    background: transparent;
    color: var(--tp-t3);
    font-size: 12px; font-weight: 600;
    font-family: var(--font-b);
    cursor: pointer;
    transition: all .2s;
    white-space: nowrap;
}
.tp-tab:hover { background: var(--tp-dim); color: var(--tp-gold); }
.tp-tab.active {
    background: var(--tp-gold);
    color: #ffffff;
}
.tp-tab i { font-size: 12px; }

/* ══════════════════════════════════════════
   EMPLOYMENT HISTORY
══════════════════════════════════════════ */
.tp-hist-episode { margin-bottom: 22px; }
.tp-hist-episode-head {
    font-size: 13px; font-weight: 700; color: var(--tp-t1);
    padding-bottom: 8px; border-bottom: 1px solid var(--tp-border); margin-bottom: 10px;
}
.tp-hist-episode-range { font-weight: 400; color: var(--tp-t2); font-size: 12px; }
.tp-hist-episode-body { display: flex; flex-direction: column; gap: 8px; }
.tp-hist-card {
    border: 1px solid var(--tp-border); border-radius: var(--tp-r);
    padding: 10px 14px; background: var(--tp-dim);
}
.tp-hist-card-head { display: flex; justify-content: space-between; align-items: baseline; gap: 10px; }
.tp-hist-type { font-size: 13px; font-weight: 700; color: var(--tp-gold); }
.tp-hist-date { font-size: 12px; color: var(--tp-t2); white-space: nowrap; }
.tp-hist-change { font-size: 13px; color: var(--tp-t1); margin-top: 4px; }
.tp-hist-meta { font-size: 11px; color: var(--tp-t3); margin-top: 4px; }
.tp-hist-reason { font-size: 12px; color: var(--tp-t2); margin-top: 6px; font-style: italic; }
.tp-hist-current {
    display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 14px; background: var(--tp-dim); border: 1px solid var(--tp-border);
    border-radius: var(--tp-r); padding: 16px 18px; margin-bottom: 20px;
}
.tp-hist-current-item { display: flex; flex-direction: column; gap: 4px; }
.tp-hist-current-lbl { font-size: 10px; letter-spacing: .04em; text-transform: uppercase; color: var(--tp-t3); }
.tp-hist-current-val { font-size: 13px; font-weight: 600; color: var(--tp-t1); }
.tp-hist-status-badge {
    display: inline-block; padding: 2px 10px; border-radius: 20px;
    font-size: 11px; font-weight: 700;
}
.tp-hist-status-badge--active { background: rgba(46, 160, 67, .15); color: #1f8a3b; }
.tp-hist-status-badge--inactive { background: rgba(200, 60, 60, .15); color: #c53c3c; }
.tp-hist-loading { color: var(--tp-t3); font-size: 13px; padding: 16px 0; }
.tp-hist-loading i { margin-right: 6px; }

/* ══════════════════════════════════════════
   PANELS
══════════════════════════════════════════ */
.tp-panel-wrap {
    background: var(--tp-card);
    border: 1px solid var(--tp-border);
    border-radius: var(--tp-r);
    box-shadow: var(--tp-sh);
    overflow: hidden;
}
.tp-panel { display: none; padding: 24px 28px 28px; }
.tp-panel.active { display: block; }

.tp-panel-head {
    font-size: 13px; font-weight: 700;
    color: var(--tp-t2);
    text-transform: uppercase; letter-spacing: .7px;
    font-family: var(--font-b);
    padding-bottom: 14px;
    border-bottom: 1px solid var(--tp-border);
    margin-bottom: 20px;
    display: flex; align-items: center; gap: 8px;
}
.tp-panel-head i { color: var(--tp-gold); }

/* Info grid */
.tp-info-grid { display: flex; flex-direction: column; gap: 0; }
.tp-info-row {
    display: flex;
    align-items: baseline;
    gap: 16px;
    padding: 12px 0;
    border-bottom: 1px solid var(--tp-border);
}
.tp-info-row:last-child { border-bottom: none; }
.tp-lbl {
    flex-shrink: 0; width: 200px;
    font-size: 12px; font-weight: 700;
    color: var(--tp-t3);
    text-transform: uppercase; letter-spacing: .5px;
    font-family: var(--font-b);
}
.tp-val {
    font-size: 14px; color: var(--tp-t1);
    font-family: var(--font-b);
    flex: 1;
    display: flex; align-items: center; gap: 8px;
}
.tp-val--mono { font-family: var(--font-m); font-size: 13px; }
.tp-val--blur span { filter: blur(5px); transition: filter .2s; }
.tp-val--blur { filter: blur(5px); user-select: none; transition: filter .2s; }
.tp-na { color: var(--tp-t3); font-style: italic; }

/* Reveal button */
.tp-reveal-btn {
    background: none; border: none; cursor: pointer;
    color: var(--tp-t3); padding: 2px 6px;
    border-radius: 4px; font-size: 12px;
    transition: color .2s;
}
.tp-reveal-btn:hover { color: var(--tp-gold); }

/* ── Salary strip ── */
.tp-salary-strip {
    display: flex;
    align-items: stretch;
    gap: 0;
    background: var(--bg3, var(--bg));
    border: 1px solid var(--tp-border);
    border-radius: 12px;
    overflow: hidden;
}
.tp-sal-cell {
    flex: 1; text-align: center; padding: 18px 12px;
}
.tp-sal-cell--net {
    background: linear-gradient(130deg, var(--bg4, #0c1e38) 0%, var(--bg, #070f1c) 100%);
    border-left: 1px solid rgba(188,90,60,.2);
}
.tp-sal-lbl {
    font-size: 10px; font-weight: 700;
    color: var(--tp-t3); text-transform: uppercase;
    letter-spacing: .6px; margin-bottom: 6px;
    font-family: var(--font-m);
}
.tp-sal-val {
    font-size: 16px; font-weight: 700;
    color: var(--tp-t1); font-family: var(--font-m);
}
.tp-sal-deduct { color: #e05c6f; }
.tp-sal-net { color: var(--tp-gold); font-size: 18px; }
.tp-sal-sep, .tp-sal-eq {
    display: flex; align-items: center; justify-content: center;
    padding: 0 8px;
    font-size: 20px; font-weight: 300;
    color: var(--tp-t3);
    flex-shrink: 0;
}
.tp-sal-eq { color: var(--tp-gold); }

/* ── Documents ── */
.tp-docs-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 16px;
}
.tp-doc-card {
    background: var(--bg3, var(--bg));
    border: 1px solid var(--tp-border);
    border-radius: 12px;
    padding: 20px 16px;
    display: flex; flex-direction: column;
    align-items: center; gap: 10px;
    text-align: center;
    transition: border-color .2s, box-shadow .2s;
}
.tp-doc-card:hover { border-color: var(--tp-gold); box-shadow: 0 0 0 3px var(--tp-dim); }
.tp-doc-icon { font-size: 28px; color: var(--tp-gold); }
.tp-doc-name {
    font-size: 12px; font-weight: 600;
    color: var(--tp-t2); word-break: break-word;
}
.tp-doc-btn {
    display: inline-flex; align-items: center; gap: 5px;
    padding: 6px 14px;
    background: linear-gradient(135deg, var(--tp-gold), var(--gold2, #9E4830));
    color: #ffffff;
    border-radius: 20px;
    font-size: 11px; font-weight: 700;
    text-decoration: none;
    transition: opacity .2s;
}
.tp-doc-btn:hover { opacity: .85; }
.tp-doc-btn--na {
    background: none;
    border: 1px dashed var(--tp-border);
    color: var(--tp-t3); cursor: default;
}
.tp-empty-docs {
    text-align: center; padding: 48px 16px;
    color: var(--tp-t3); font-size: 14px; line-height: 2;
}
.tp-empty-docs i { font-size: 36px; display: block; margin-bottom: 8px; opacity: .4; }

/* ── Responsive ── */
@media (max-width: 640px) {
    .tp-hero-inner { flex-direction: column; align-items: flex-start; gap: 16px; }
    .tp-hero-name { font-size: 18px; white-space: normal; }
    .tp-stats { flex-wrap: wrap; gap: 8px; }
    .tp-stat-div { display: none; }
    .tp-lbl { width: 140px; font-size: 11px; }
    .tp-salary-strip { flex-direction: column; }
    .tp-sal-eq, .tp-sal-sep { transform: rotate(90deg); padding: 4px 0; }
}
</style>

<div class="content-wrapper">
<div class="tp-wrap">

    <!-- ── Breadcrumb ── -->
    <ol class="tp-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('staff/new_staff') ?>">Staff</a></li>
        <li>Teacher Profile</li>
    </ol>

    <!-- ══════════════════════════════════════════
         HERO CARD
    ══════════════════════════════════════════ -->
    <div class="tp-hero">
        <div class="tp-hero-inner">

            <!-- Avatar -->
            <div class="tp-avatar-wrap">
                <img class="tp-avatar"
                     src="<?= $picUrl ?: $defaultPic ?>"
                     onerror="this.src='<?= $defaultPic ?>'"
                     alt="<?= $name ?>">
                <span class="tp-avatar-badge">
                    <?= $gender === 'Female' ? '♀' : ($gender === 'Male' ? '♂' : '●') ?>
                </span>
            </div>

            <!-- Identity -->
            <div class="tp-hero-info">
                <div class="tp-hero-name"><?= $name ?></div>

                <div class="tp-hero-meta">
                    <?php if ($position): ?>
                    <span class="tp-chip"><i class="fa fa-briefcase"></i> <?= $position ?></span>
                    <?php endif; ?>
                    <?php if ($dept): ?>
                    <span class="tp-chip"><i class="fa fa-building-o"></i> <?= $dept ?></span>
                    <?php endif; ?>
                    <?php if ($tid): ?>
                    <span class="tp-chip tp-chip--mono"><i class="fa fa-id-badge"></i> <?= $tid ?></span>
                    <?php endif; ?>
                    <?php if (strtolower((string) ($t['status'] ?? $t['Status'] ?? 'active')) === 'inactive'): ?>
                    <span class="tp-chip tp-chip--inactive"><i class="fa fa-ban"></i> Inactive / Separated</span>
                    <button type="button" class="tp-rehire-btn" onclick="tpOpenRehireModal()">
                        <i class="fa fa-undo"></i> Rehire Staff
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Quick stats strip -->
                <div class="tp-stats">
                    <div class="tp-stat">
                        <div class="tp-stat-val"><?= $joining ?: '—' ?></div>
                        <div class="tp-stat-lbl">Joined</div>
                    </div>
                    <div class="tp-stat-div"></div>
                    <div class="tp-stat">
                        <div class="tp-stat-val"><?= $experience ?: '—' ?></div>
                        <div class="tp-stat-lbl">Experience</div>
                    </div>
                    <div class="tp-stat-div"></div>
                    <div class="tp-stat">
                        <div class="tp-stat-val"><?= htmlspecialchars($t['Employment Type'] ?? '—', ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="tp-stat-lbl">Employment</div>
                    </div>
                </div>
            </div>

        </div><!-- /.tp-hero-inner -->
    </div><!-- /.tp-hero -->

    <!-- ══════════════════════════════════════════
         TAB BAR
    ══════════════════════════════════════════ -->
    <div class="tp-tabs" id="tpTabs">
        <button class="tp-tab active" data-target="tp-personal">
            <i class="fa fa-user"></i> Personal
        </button>
        <button class="tp-tab" data-target="tp-professional">
            <i class="fa fa-briefcase"></i> Professional
        </button>
        <button class="tp-tab" data-target="tp-salary">
            <i class="fa fa-money"></i> Salary
        </button>
        <button class="tp-tab" data-target="tp-emergency">
            <i class="fa fa-ambulance"></i> Emergency
        </button>
        <button class="tp-tab" data-target="tp-bank">
            <i class="fa fa-university"></i> Bank
        </button>
        <button class="tp-tab" data-target="tp-qualification">
            <i class="fa fa-graduation-cap"></i> Qualification
        </button>
        <button class="tp-tab" data-target="tp-docs">
            <i class="fa fa-folder-open"></i> Documents
        </button>
        <button class="tp-tab" data-target="tp-history">
            <i class="fa fa-history"></i> History
        </button>
    </div>

    <!-- ══════════════════════════════════════════
         TAB PANELS
    ══════════════════════════════════════════ -->
    <div class="tp-panel-wrap">

        <!-- ── Personal Details ── -->
        <div class="tp-panel active" id="tp-personal">
            <div class="tp-panel-head"><i class="fa fa-user"></i> Personal Details</div>
            <div class="tp-info-grid">
                <div class="tp-info-row">
                    <span class="tp-lbl">Full Name</span>
                    <span class="tp-val"><?= tp_val($t['Name'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Father's Name</span>
                    <span class="tp-val"><?= tp_val($t['Father Name'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Gender</span>
                    <span class="tp-val"><?= tp_val($t['Gender'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Date of Birth</span>
                    <span class="tp-val"><?= tp_val($t['DOB'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Category</span>
                    <span class="tp-val"><?= tp_val($t['Category'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Email</span>
                    <span class="tp-val"><?= tp_val($t['Email'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Phone Number</span>
                    <span class="tp-val"><?= tp_val($t['Phone Number'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Address</span>
                    <span class="tp-val">
                    <?php
                    $addr = $t['Address'] ?? '';
                    if (is_array($addr)) {
                        $parts = array_filter([
                            $addr['Street']     ?? '',
                            $addr['City']       ?? '',
                            $addr['State']      ?? '',
                            $addr['PostalCode'] ?? '',
                        ]);
                        echo $parts ? htmlspecialchars(implode(', ', $parts), ENT_QUOTES, 'UTF-8') : '<span class="tp-na">N/A</span>';
                    } else {
                        echo tp_val($addr);
                    }
                    ?>
                    </span>
                </div>
            </div>
        </div>

        <!-- ── Professional Details ── -->
        <div class="tp-panel" id="tp-professional">
            <div class="tp-panel-head"><i class="fa fa-briefcase"></i> Professional Details</div>
            <div class="tp-info-grid">
                <div class="tp-info-row">
                    <span class="tp-lbl">Position</span>
                    <span class="tp-val"><?= tp_val($t['Position'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Department</span>
                    <span class="tp-val"><?= tp_val($t['Department'] ?? $t['department'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Date of Joining</span>
                    <span class="tp-val"><?= tp_val($t['Date Of Joining'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Employment Type</span>
                    <span class="tp-val"><?= tp_val($t['Employment Type'] ?? '') ?></span>
                </div>
                <!-- <div class="tp-info-row">
                    <span class="tp-lbl">School</span>
                    <span class="tp-val"><?= tp_val($t['School Name'] ?? '') ?></span>
                </div> -->
                <div class="tp-info-row">
                    <span class="tp-lbl">Reporting Manager</span>
                    <span class="tp-val">
                        <?= isset($reportingManagerName) ? tp_val($reportingManagerName) : tp_val('') ?>
                        <?php if (!empty($reportingManagerName) && (($reportingManagerStatus ?? '') === 'Inactive')): ?>
                        <i class="fa fa-exclamation-triangle" style="color:#c53c3c;font-size:11px;margin-left:6px;" title="This Reporting Manager is currently Inactive. HR should review and reassign via Staff Edit."></i>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Teacher ID</span>
                    <span class="tp-val tp-val--mono"><?= tp_val($t['User ID'] ?? '') ?></span>
                </div>
            </div>
        </div>

        <!-- ── Salary Details ── -->
        <div class="tp-panel" id="tp-salary">
            <div class="tp-panel-head"><i class="fa fa-money"></i> Salary Details</div>

            <?php
            $basic   = $t['salaryDetails']['basicSalary'] ?? 0;
            $allow   = $t['salaryDetails']['Allowances'] ?? 0;
            $net     = $t['salaryDetails']['Net Salary'] ?? 0;
            $pf      = $t['salaryDetails']['deductions']['PF'] ?? 0;
            $tax     = $t['salaryDetails']['deductions']['ProfessionalTax'] ?? 0;
            $deduct  = (float)$pf + (float)$tax;
            ?>

            <!-- Salary summary strip -->
            <div class="tp-salary-strip">
                <div class="tp-sal-cell">
                    <div class="tp-sal-lbl">Basic Salary</div>
                    <div class="tp-sal-val"><?= is_numeric($basic) && $basic > 0 ? '₹' . number_format((float)$basic, 2) : '—' ?></div>
                </div>
                <div class="tp-sal-sep">+</div>
                <div class="tp-sal-cell">
                    <div class="tp-sal-lbl">Allowances</div>
                    <div class="tp-sal-val"><?= is_numeric($allow) && $allow > 0 ? '₹' . number_format((float)$allow, 2) : '—' ?></div>
                </div>
                <?php if ($deduct > 0): ?>
                <div class="tp-sal-sep">−</div>
                <div class="tp-sal-cell">
                    <div class="tp-sal-lbl">Deductions</div>
                    <div class="tp-sal-val tp-sal-deduct">₹<?= number_format($deduct, 2) ?></div>
                </div>
                <?php endif; ?>
                <div class="tp-sal-eq">=</div>
                <div class="tp-sal-cell tp-sal-cell--net">
                    <div class="tp-sal-lbl">Net Salary</div>
                    <div class="tp-sal-val tp-sal-net"><?= is_numeric($net) && $net > 0 ? '₹' . number_format((float)$net, 2) : '—' ?></div>
                </div>
            </div>

            <div class="tp-info-grid" style="margin-top:20px;">
                <?php if ($pf): ?>
                <div class="tp-info-row">
                    <span class="tp-lbl">PF Deduction</span>
                    <span class="tp-val"><?= tp_money($pf) ?></span>
                </div>
                <?php endif; ?>
                <?php if ($tax): ?>
                <div class="tp-info-row">
                    <span class="tp-lbl">Professional Tax</span>
                    <span class="tp-val"><?= tp_money($tax) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Emergency Details ── -->
        <div class="tp-panel" id="tp-emergency">
            <div class="tp-panel-head"><i class="fa fa-ambulance"></i> Emergency Contact</div>
            <div class="tp-info-grid">
                <div class="tp-info-row">
                    <span class="tp-lbl">Contact Name</span>
                    <span class="tp-val"><?= tp_val($t['emergencyContact']['name'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Phone Number</span>
                    <span class="tp-val tp-val--mono"><?= tp_val($t['emergencyContact']['phoneNumber'] ?? '') ?></span>
                </div>
                
            </div>
        </div>

        <!-- ── Bank Details ── -->
        <div class="tp-panel" id="tp-bank">
            <div class="tp-panel-head"><i class="fa fa-university"></i> Bank Details</div>
            <div class="tp-info-grid">
                <div class="tp-info-row">
                    <span class="tp-lbl">Bank Name</span>
                    <span class="tp-val"><?= tp_val($t['bankDetails']['bankName'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Account Holder</span>
                    <span class="tp-val"><?= tp_val($t['bankDetails']['accountHolderName'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Account Number</span>
                    <span class="tp-val tp-val--mono tp-val--blur" id="tpAccNum">
                        <?= tp_val($t['bankDetails']['accountNumber'] ?? '') ?>
                        <button class="tp-reveal-btn" onclick="tpReveal('tpAccNum', this)" title="Show/Hide">
                            <i class="fa fa-eye"></i>
                        </button>
                    </span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">IFSC Code</span>
                    <span class="tp-val tp-val--mono"><?= tp_val($t['bankDetails']['ifscCode'] ?? '') ?></span>
                </div>
            </div>
        </div>

        <!-- ── Qualification Details ── -->
        <div class="tp-panel" id="tp-qualification">
            <div class="tp-panel-head"><i class="fa fa-graduation-cap"></i> Qualification &amp; Experience</div>
            <div class="tp-info-grid">
                <div class="tp-info-row">
                    <span class="tp-lbl">Highest Qualification</span>
                    <span class="tp-val"><?= tp_val($t['qualificationDetails']['highestQualification'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">University / Board</span>
                    <span class="tp-val"><?= tp_val($t['qualificationDetails']['university'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Year of Passing</span>
                    <span class="tp-val tp-val--mono"><?= tp_val($t['qualificationDetails']['yearOfPassing'] ?? '') ?></span>
                </div>
                <div class="tp-info-row">
                    <span class="tp-lbl">Experience</span>
                    <span class="tp-val"><?= tp_val($t['qualificationDetails']['experience'] ?? '') ?></span>
                </div>
                <!-- <div class="tp-info-row">
                    <span class="tp-lbl">Specialization</span>
                    <span class="tp-val"><?= tp_val($t['qualificationDetails']['specialization'] ?? '') ?></span>
                </div> -->
            </div>
        </div>

        <!-- ── Documents ── -->
        <div class="tp-panel" id="tp-docs">
            <div class="tp-panel-head"><i class="fa fa-folder-open"></i> Documents</div>
            <?php if (!empty($t['Doc']) && is_array($t['Doc'])): ?>
            <div class="tp-docs-grid">
                <?php foreach ($t['Doc'] as $docName => $docUrl): ?>
                <?php
                $dn  = htmlspecialchars((string)$docName, ENT_QUOTES, 'UTF-8');
                // $docUrl may be a string URL or an array {url:"...", uploaded_at:"..."}
                $rawUrl = $docUrl;
                if (is_array($rawUrl)) {
                    $rawUrl = $rawUrl['url'] ?? $rawUrl['URL'] ?? '';
                }
                $rawUrl = is_string($rawUrl) ? $rawUrl : '';
                $du  = htmlspecialchars($rawUrl, ENT_QUOTES, 'UTF-8');
                $ext = strtolower(pathinfo($rawUrl, PATHINFO_EXTENSION));
                $isImg = in_array($ext, ['jpg','jpeg','png','webp','gif']);
                $icon  = $ext === 'pdf' ? 'fa-file-pdf-o' : ($isImg ? 'fa-file-image-o' : 'fa-file-o');
                ?>
                <div class="tp-doc-card">
                    <div class="tp-doc-icon"><i class="fa <?= $icon ?>"></i></div>
                    <div class="tp-doc-name"><?= $dn ?></div>
                    <?php if ($du): ?>
                    <a href="<?= base_url('student/download_document?file=') . urlencode($rawUrl) ?>"
                       class="tp-doc-btn" target="_blank">
                        <i class="fa fa-download"></i> Download
                    </a>
                    <?php else: ?>
                    <span class="tp-doc-btn tp-doc-btn--na">Not uploaded</span>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="tp-empty-docs">
                <i class="fa fa-folder-open-o"></i>
                <p>No documents uploaded yet.</p>
            </div>
            <?php endif; ?>
        </div>

        <!-- ── Employment History ── -->
        <div class="tp-panel" id="tp-history">
            <div class="tp-panel-head"><i class="fa fa-history"></i> Employment History</div>

            <!-- Current-state summary — rendered server-side from the SAME
                 $t data every other tab already uses, so it's never empty
                 even before the history fetch (or if it never returns). -->
            <div class="tp-hist-current">
                <div class="tp-hist-current-item">
                    <span class="tp-hist-current-lbl">Position</span>
                    <span class="tp-hist-current-val"><?= tp_val($t['Position'] ?? '') ?></span>
                </div>
                <div class="tp-hist-current-item">
                    <span class="tp-hist-current-lbl">Department</span>
                    <span class="tp-hist-current-val"><?= tp_val($t['Department'] ?? $t['department'] ?? '') ?></span>
                </div>
                <div class="tp-hist-current-item">
                    <span class="tp-hist-current-lbl">Employment Type</span>
                    <span class="tp-hist-current-val"><?= tp_val($t['Employment Type'] ?? '') ?></span>
                </div>
                <div class="tp-hist-current-item">
                    <span class="tp-hist-current-lbl">Reporting Manager</span>
                    <span class="tp-hist-current-val">
                        <?= isset($reportingManagerName) ? tp_val($reportingManagerName) : tp_val('') ?>
                        <?php if (!empty($reportingManagerName) && (($reportingManagerStatus ?? '') === 'Inactive')): ?>
                        <i class="fa fa-exclamation-triangle" style="color:#c53c3c;font-size:11px;margin-left:6px;" title="This Reporting Manager is currently Inactive. HR should review and reassign via Staff Edit."></i>
                        <?php endif; ?>
                    </span>
                </div>
                <div class="tp-hist-current-item">
                    <span class="tp-hist-current-lbl">Status</span>
                    <span class="tp-hist-current-val">
                        <span class="tp-hist-status-badge tp-hist-status-badge--<?= strtolower($t['status'] ?? $t['Status'] ?? 'active') === 'active' ? 'active' : 'inactive' ?>">
                            <?= tp_val($t['status'] ?? $t['Status'] ?? '') ?>
                        </span>
                    </span>
                </div>
            </div>

            <div id="tpHistoryLoading" class="tp-hist-loading"><i class="fa fa-circle-o-notch fa-spin"></i> Loading history…</div>
            <div id="tpHistoryEmpty" class="tp-empty-docs" style="display:none;">
                <i class="fa fa-history"></i>
                <p id="tpHistoryEmptyMsg"></p>
            </div>
            <div id="tpHistoryEpisodes"></div>
        </div>

    </div><!-- /.tp-panel-wrap -->

    <!-- ══════════════════════════════════════════
         REHIRE MODAL — only ever shown when status is Inactive
         (the trigger button itself is server-gated the same way);
         server-side validation in Staff::set_status() is the actual
         authorization/eligibility boundary, this is UX only.
    ══════════════════════════════════════════ -->
    <div class="tp-modal-overlay" id="tpRehireOverlay">
        <div class="tp-modal">
            <div class="tp-modal-head">
                <h4><i class="fa fa-undo"></i> Rehire Staff</h4>
                <button type="button" class="tp-modal-close" onclick="tpCloseRehireModal()">&times;</button>
            </div>
            <div class="tp-modal-body">
                <div class="tp-modal-error" id="tpRehireError"></div>
                <p style="font-size:12px;color:var(--tp-t2);margin:0;">
                    This starts a new employment episode for <strong><?= $name ?></strong>. Their previous
                    employment history, including the separation record, remains permanently on file.
                </p>
                <div class="tp-modal-field">
                    <label>Rehire Effective Date</label>
                    <input type="date" id="tpRehireDate" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="tp-modal-field">
                    <label>Position <span style="color:var(--tp-t3);font-weight:400;">(leave as-is to keep unchanged)</span></label>
                    <input type="text" id="tpRehirePosition" placeholder="<?= tp_val($t['Position'] ?? '') ?>">
                </div>
                <div class="tp-modal-field">
                    <label>Department</label>
                    <select id="tpRehireDepartment">
                        <option value="">-- Keep unchanged --</option>
                    </select>
                </div>
                <div class="tp-modal-field">
                    <label>Employment Type</label>
                    <input type="text" id="tpRehireEmpType" placeholder="<?= tp_val($t['Employment Type'] ?? '') ?>">
                </div>
                <div class="tp-modal-field">
                    <label>Reporting Manager</label>
                    <select id="tpRehireManager">
                        <option value="">-- Keep unchanged --</option>
                    </select>
                </div>
                <div class="tp-modal-field">
                    <label>Reason / Notes</label>
                    <textarea id="tpRehireReason" rows="2" placeholder="e.g. Returning after resignation"></textarea>
                </div>
            </div>
            <div class="tp-modal-foot">
                <button type="button" class="tp-modal-btn tp-modal-btn--cancel" onclick="tpCloseRehireModal()">Cancel</button>
                <button type="button" class="tp-modal-btn tp-modal-btn--submit" id="tpRehireSubmitBtn" onclick="tpSubmitRehire()">Rehire</button>
            </div>
        </div>
    </div>

</div><!-- /.tp-wrap -->
</div><!-- /.content-wrapper -->


<script>
/* ── Teacher Profile tab switching ── */
(function () {
    var tabs   = document.querySelectorAll('.tp-tab');
    var panels = document.querySelectorAll('.tp-panel');

    tabs.forEach(function (tab) {
        tab.addEventListener('click', function () {
            tabs.forEach(function (t) { t.classList.remove('active'); });
            panels.forEach(function (p) { p.classList.remove('active'); });
            tab.classList.add('active');
            var target = document.getElementById(tab.dataset.target);
            if (target) target.classList.add('active');
        });
    });
})();

/* ── Employment History (lazy-loaded on first tab activation) ── */
(function () {
    var staffId = <?= json_encode($t['User ID'] ?? '') ?>;
    var loaded = false;
    var historyTab = document.querySelector('.tp-tab[data-target="tp-history"]');
    if (!historyTab || !staffId) return;

    var EVENT_LABELS = {
        'JOINED': 'Joined',
        'POSITION_CHANGED': 'Position changed',
        'DEPARTMENT_CHANGED': 'Department changed',
        'EMPLOYMENT_TYPE_CHANGED': 'Employment Type changed',
        'REPORTING_MANAGER_CHANGED': 'Reporting Manager changed',
        'SEPARATED': 'Separated',
        'REACTIVATED': 'Reactivated',
        'REHIRED': 'Rehired'
    };

    function esc(s) {
        var d = document.createElement('div');
        d.textContent = (s === null || s === undefined) ? '' : String(s);
        return d.innerHTML;
    }
    function fmtDate(s) {
        if (!s) return '—';
        return esc(s);
    }

    function renderEventCard(evt, names, separations) {
        var label = EVENT_LABELS[evt.eventType] || evt.eventType;
        var changeLine = '';
        if (evt.eventType === 'JOINED') {
            var m = evt.metadata || {};
            changeLine = 'Position: ' + esc(m.position || '—') + ' · Department: ' + esc(m.department || '—') +
                ' · Employment Type: ' + esc(m.employmentType || '—');
        } else if (evt.fromValue || evt.toValue) {
            changeLine = esc(evt.fromValue || '—') + ' &rarr; ' + esc(evt.toValue || '—');
        }
        var who = evt.changed_by ? (names[evt.changed_by] || evt.changed_by) : '—';
        var reasonLine = '';
        if (evt.eventType === 'SEPARATED' && evt.hasOwnProperty('reason')) {
            reasonLine = evt.reason ? ('<div class="tp-hist-reason"><i class="fa fa-lock"></i> ' + esc(evt.reason) + '</div>') : '';
        }
        return '' +
            '<div class="tp-hist-card">' +
              '<div class="tp-hist-card-head">' +
                '<span class="tp-hist-type">' + esc(label) + '</span>' +
                '<span class="tp-hist-date">' + fmtDate(evt.effectiveFrom) + '</span>' +
              '</div>' +
              (changeLine ? '<div class="tp-hist-change">' + changeLine + '</div>' : '') +
              '<div class="tp-hist-meta">By ' + esc(who) + '</div>' +
              reasonLine +
            '</div>';
    }

    function showEmpty(message) {
        document.getElementById('tpHistoryEmpty').style.display = 'block';
        document.getElementById('tpHistoryEmptyMsg').textContent = message;
    }

    function renderEpisodes(data) {
        var episodesEl = document.getElementById('tpHistoryEpisodes');
        episodesEl.innerHTML = '';

        if (!data.hasHistory) {
            showEmpty('No Employment History is available for this staff member yet — the current profile data above reflects their present state.');
            return;
        }

        // Group events by episode using each event's effectiveFrom against episode [start,end] windows.
        var episodes = data.episodes && data.episodes.length ? data.episodes : [{ start: null, end: null, startEvent: 'JOINED' }];
        var html = '';
        for (var i = episodes.length - 1; i >= 0; i--) {
            var ep = episodes[i];
            var rangeLabel = fmtDate(ep.start) + ' &rarr; ' + (ep.end ? fmtDate(ep.end) : 'Current');
            var epEvents = data.events.filter(function (e) {
                if (ep.start && e.effectiveFrom < ep.start) return false;
                if (ep.end && e.effectiveFrom > ep.end) return false;
                return true;
            }).slice().reverse();
            html += '<div class="tp-hist-episode">' +
                        '<div class="tp-hist-episode-head"><i class="fa fa-briefcase"></i> Employment Episode ' + (i + 1) + ' &nbsp;<span class="tp-hist-episode-range">' + rangeLabel + '</span></div>' +
                        '<div class="tp-hist-episode-body">' +
                            epEvents.map(function (e) { return renderEventCard(e, data.changedByNames || {}, data.separations || []); }).join('') +
                        '</div>' +
                    '</div>';
        }
        episodesEl.innerHTML = html;
    }

    function load() {
        if (loaded) return;
        loaded = true;
        fetch('<?= base_url('staff/get_employment_history/') ?>' + encodeURIComponent(staffId), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
            .then(function (r) { return r.json(); })
            .then(function (resp) {
                document.getElementById('tpHistoryLoading').style.display = 'none';
                if (resp.status !== 'success') {
                    showEmpty(resp.message || 'Could not load employment history.');
                    return;
                }
                renderEpisodes(resp);
            })
            .catch(function () {
                document.getElementById('tpHistoryLoading').style.display = 'none';
                showEmpty('Could not load employment history.');
            });
    }

    historyTab.addEventListener('click', load);
})();

/* ── Rehire Workflow ── */
(function () {
    var staffId = <?= json_encode($t['User ID'] ?? '') ?>;
    var ownStaffId = staffId;
    var deptLoaded = false, mgrLoaded = false;

    window.tpOpenRehireModal = function () {
        document.getElementById('tpRehireOverlay').classList.add('tp-modal-open');
        document.getElementById('tpRehireError').style.display = 'none';
        if (!deptLoaded) {
            deptLoaded = true;
            fetch('<?= base_url("hr/get_departments") ?>')
                .then(function (r) { return r.json(); })
                .then(function (r) {
                    if (r.status !== 'success' || !r.departments) return;
                    var sel = document.getElementById('tpRehireDepartment');
                    r.departments.forEach(function (d) {
                        var opt = document.createElement('option');
                        opt.value = d.name || d; opt.textContent = d.name || d;
                        sel.appendChild(opt);
                    });
                }).catch(function () {});
        }
        if (!mgrLoaded) {
            mgrLoaded = true;
            fetch('<?= base_url("hr/get_staff_list") ?>', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (resp) {
                    var staff = resp.staff || (resp.data && resp.data.staff) || [];
                    var sel = document.getElementById('tpRehireManager');
                    staff.forEach(function (s) {
                        if (s.id === ownStaffId) return; // no self-report
                        var opt = document.createElement('option');
                        opt.value = s.id;
                        opt.textContent = s.name + (s.position ? ' — ' + s.position : '');
                        sel.appendChild(opt);
                    });
                }).catch(function () {});
        }
    };

    window.tpCloseRehireModal = function () {
        document.getElementById('tpRehireOverlay').classList.remove('tp-modal-open');
    };

    window.tpSubmitRehire = function () {
        var errEl = document.getElementById('tpRehireError');
        errEl.style.display = 'none';
        var btn = document.getElementById('tpRehireSubmitBtn');
        btn.disabled = true;
        var originalHtml = btn.innerHTML;
        btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Rehiring…';

        var body = new URLSearchParams();
        body.append('status', 'Active');
        body.append('effective_date', document.getElementById('tpRehireDate').value || '');
        body.append('reason', document.getElementById('tpRehireReason').value || '');
        var pos = document.getElementById('tpRehirePosition').value.trim();
        if (pos !== '') body.append('position', pos);
        var dept = document.getElementById('tpRehireDepartment').value;
        if (dept !== '') body.append('department', dept);
        var emp = document.getElementById('tpRehireEmpType').value.trim();
        if (emp !== '') body.append('employment_type', emp);
        var mgr = document.getElementById('tpRehireManager').value;
        if (mgr !== '') body.append('reporting_manager_id', mgr);
        body.append('<?= $this->security->get_csrf_token_name() ?>', '<?= $this->security->get_csrf_hash() ?>');

        fetch('<?= base_url("staff/set_status/") ?>' + encodeURIComponent(staffId), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        })
        .then(function (r) { return r.json().catch(function () { return { status: 'error', message: 'Server returned non-JSON.' }; }); })
        .then(function (res) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            if (!res || res.status !== 'success') {
                errEl.textContent = (res && res.message) ? res.message : 'Rehire failed.';
                errEl.style.display = 'block';
                return;
            }
            window.location.reload();
        })
        .catch(function (err) {
            btn.disabled = false;
            btn.innerHTML = originalHtml;
            errEl.textContent = 'Network error: ' + (err && err.message ? err.message : 'unknown');
            errEl.style.display = 'block';
        });
    };
})();

/* ── Account number reveal/hide ── */
function tpReveal(id, btn) {
    var el = document.getElementById(id);
    if (!el) return;
    el.classList.toggle('tp-val--blur');
    btn.querySelector('i').className = el.classList.contains('tp-val--blur')
        ? 'fa fa-eye' : 'fa fa-eye-slash';
}
</script>


