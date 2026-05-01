<?php
defined('BASEPATH') or exit('No direct script access allowed');

// QR identity helper — produces the URL-safe `base64("{schoolId}|{studentId}")`
// token used by the attendance scan endpoint. Loaded here (not auto-loaded)
// so the helper stays opt-in for views that actually need it.
$CI =& get_instance();
$CI->load->helper('qr_token');

$students       = $students       ?? [];
$session_year   = $session_year   ?? '';
$school_profile = $school_profile ?? [];

$schoolN    = $school_profile['school_name'] ?? $school_name ?? '';
$schoolAddr = $school_profile['address']     ?? '';
$schoolLogo = $school_profile['logo']        ?? base_url('tools/image/default-school.jpeg');
$schoolPhone= $school_profile['phone']       ?? '';
$fallback   = base_url('tools/image/default-school.jpeg');

// schoolId for QR encoding — `$school_name` in this codebase IS the SCH_xxx
// schoolId per the project's non-obvious-conventions memory. Falls back to
// an empty string if the controller didn't pass it; the per-card render
// then skips the QR (no token, no img).
$qrSchoolId = (string) ($school_name ?? '');

function idc_photo($s) {
    if (!empty($s['Profile Pic']) && is_string($s['Profile Pic'])) return $s['Profile Pic'];
    if (!empty($s['Doc']['Photo'])) {
        $e = $s['Doc']['Photo'];
        return is_array($e) ? ($e['url'] ?? '') : $e;
    }
    return '';
}

function idc_dob($raw) {
    if (!$raw) return '';
    foreach (['Y-m-d','d-m-Y','d/m/Y','m/d/Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw);
        if ($dt) return $dt->format('d M Y');
    }
    return $raw;
}

/**
 * Idempotent prefix normaliser. Adds the prefix only when missing —
 * post Phase-1 canonical migration most rows arrive as "Class 8th" /
 * "Section A" already, but legacy / partial rows may carry just
 * "8th" / "A". Either shape now produces the canonical "Class 8th" /
 * "Section A" once and only once. Pre-fix the display template did
 * `Class <?= $cls ?>` which double-prefixed canonical rows into
 * "Class Class 8th".
 */
function idc_with_prefix($val, $prefix) {
    $v = trim((string) $val);
    if ($v === '') return '';
    return (stripos($v, $prefix . ' ') === 0) ? $v : ($prefix . ' ' . $v);
}

$filterClasses  = [];
$filterSections = [];
foreach ($students as $s) {
    // Normalise once so dropdown options + per-card data-attrs use the
    // same canonical "Class 8th" / "Section A" strings (filter equality
    // depends on this match — if dropdown carries "8th" but data-class
    // carries "Class 8th", filtering silently breaks).
    $c   = idc_with_prefix($s['Class']   ?? '', 'Class');
    $sec = idc_with_prefix($s['Section'] ?? '', 'Section');
    if ($c)   $filterClasses[$c]    = true;
    if ($sec) $filterSections[$sec] = true;
}
ksort($filterClasses);
ksort($filterSections);
?>

<style>
:root { --idc: var(--gold, #0f766e); --idc-lt: rgba(15,118,110,.1); }

.idc-wrap { max-width:1280px; margin:0 auto; padding:24px 20px; }

/* ── Top Bar ── */
.idc-topbar { display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:12px; margin-bottom:20px; }
.idc-topbar h1 { margin:0; font-size:1.2rem; color:var(--t1); font-family:var(--font-b); display:flex; align-items:center; gap:8px; }
.idc-topbar h1 i { color:var(--idc); }
.idc-actions { display:flex; gap:8px; }
.idc-btn { padding:8px 16px; border:none; border-radius:8px; cursor:pointer; font-size:.82rem; font-weight:600;
    display:inline-flex; align-items:center; gap:6px; transition:all .2s; }
.idc-btn-primary { background:var(--idc); color:#fff; }
.idc-btn-primary:hover { opacity:.85; }
.idc-btn-outline { background:transparent; color:var(--idc); border:1.5px solid var(--idc); }
.idc-btn-outline:hover { background:var(--idc-lt); }

/* ── Filter Bar ── */
.idc-filters { display:flex; gap:10px; flex-wrap:wrap; align-items:center; margin-bottom:20px;
    padding:12px 16px; background:var(--bg2); border:1px solid var(--border); border-radius:10px; }
.idc-search-wrap { position:relative; flex:1; min-width:180px; }
.idc-search-wrap i { position:absolute; left:11px; top:50%; transform:translateY(-50%); color:var(--t3); font-size:.85rem; }
.idc-search { width:100%; padding:8px 12px 8px 34px; border:1.5px solid var(--border); border-radius:8px;
    background:var(--bg); color:var(--t1); font-size:.85rem; outline:none; }
.idc-search:focus { border-color:var(--idc); }
.idc-select { padding:8px 12px; border:1.5px solid var(--border); border-radius:8px; background:var(--bg);
    color:var(--t1); font-size:.82rem; min-width:110px; }
.idc-counter { font-size:.82rem; color:var(--t2); white-space:nowrap; }
.idc-counter b { color:var(--idc); }

.idc-empty { text-align:center; padding:60px 20px; color:var(--t3); background:var(--bg2);
    border:1px solid var(--border); border-radius:10px; }
.idc-empty i { font-size:2.5rem; display:block; margin-bottom:10px; }

/* ── Cards Grid ── */
.idc-grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); gap:20px; justify-items:center; }

/* ══════════════════════════════════════════════════════
   THE ID CARD
   ══════════════════════════════════════════════════════ */
.idc-card {
    width:260px; border-radius:10px; overflow:hidden; background:var(--bg2);
    box-shadow:0 2px 12px rgba(0,0,0,.08); font-family:sans-serif;
    page-break-inside:avoid; transition:transform .2s, box-shadow .2s;
}
.idc-card:hover { transform:translateY(-2px); box-shadow:0 6px 20px rgba(0,0,0,.12); }

/* ── Header: Logo left + School name & address right ── */
.idc-hdr {
    background:var(--idc); padding:10px 12px; display:flex; align-items:center; gap:8px;
}
.idc-hdr-logo {
    width:36px; height:36px; border-radius:50%; object-fit:contain; background:#fff; padding:2px; flex-shrink:0;
}
.idc-hdr-info { flex:1; min-width:0; }
.idc-hdr-name { color:#fff; font-size:.74rem; font-weight:700; line-height:1.25;
    overflow:hidden; text-overflow:ellipsis; display:-webkit-box; -webkit-line-clamp:2; -webkit-box-orient:vertical; }
.idc-hdr-addr { color:rgba(255,255,255,.72); font-size:.6rem; line-height:1.3; margin-top:1px;
    overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }

/* ── Photo — sits below header, no overlap ── */
.idc-photo-area { display:flex; justify-content:center; padding:12px 0 6px; background:var(--bg2); }
.idc-photo {
    width:72px; height:72px; border-radius:50%; object-fit:cover;
    border:3px solid var(--idc); box-shadow:0 2px 8px rgba(0,0,0,.1); background:var(--bg3);
}
.idc-photo-fb {
    width:72px; height:72px; border-radius:50%; display:flex; align-items:center; justify-content:center;
    border:3px solid var(--idc); box-shadow:0 2px 8px rgba(0,0,0,.1);
    background:var(--idc-lt); color:var(--idc); font-size:1.6rem; font-weight:700; font-family:var(--font-b);
}

/* ── Body ── */
.idc-body { padding:4px 14px 10px; text-align:center; }

.idc-name { font-size:.92rem; font-weight:700; color:var(--t1); font-family:var(--font-b);
    line-height:1.3; margin-bottom:1px; word-break:break-word; }
.idc-class { font-size:.72rem; color:var(--t2); margin-bottom:6px; font-weight:500; }

/* QR — local SVG render via chillerlan/php-qrcode embedded as a data
   URI. Vector means no pixelation at any zoom level — print-friendly
   by default. Slightly smaller (76px) than the previous 92px because
   the user wanted a tighter card layout; SVG stays sharp regardless. */
.idc-qr-wrap { display:flex; justify-content:center; margin:4px 0 6px; }
.idc-qr-wrap img { width:76px; height:76px; }

/* ── Info rows ── */
.idc-info { padding:0 14px 8px; }
.idc-info-row { display:flex; align-items:center; gap:6px; padding:3px 0;
    border-bottom:1px solid var(--border); font-size:.73rem; }
.idc-info-row:last-child { border-bottom:none; }
.idc-info-ico { color:var(--idc); width:14px; text-align:center; flex-shrink:0; font-size:.7rem; }
.idc-info-lbl { color:var(--t3); min-width:48px; font-size:.68rem; }
.idc-info-val { color:var(--t1); font-weight:600; flex:1; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; }
.idc-info-val.idc-blood { color:#dc2626; }
.idc-info-val.idc-phone { color:#2563eb; }

/* ── Footer ── */
.idc-ftr { background:var(--idc); padding:6px 12px; display:flex; align-items:center; justify-content:space-between; }
.idc-ftr-label { font-size:.6rem; color:rgba(255,255,255,.9); font-weight:600; text-transform:uppercase; letter-spacing:.5px; }
.idc-ftr-valid { font-size:.58rem; color:rgba(255,255,255,.65); }

/* Print one btn */
.idc-print-one { margin-top:6px; padding:5px 14px; border:1px solid var(--border); border-radius:6px;
    background:var(--bg2); color:var(--t2); cursor:pointer; font-size:.75rem; transition:all .2s; }
.idc-print-one:hover { border-color:var(--idc); color:var(--idc); }

@media print {
    .no-print { display:none !important; }
    .idc-grid { grid-template-columns:repeat(3, 260px); gap:8mm; padding:8mm; }
    .idc-card { box-shadow:none; border:1px solid #ccc; }
    .idc-card:hover { transform:none; }
    .idc-print-one { display:none; }
}
</style>

<div class="content-wrapper">
<div class="idc-wrap">

    <div class="idc-topbar no-print">
        <h1><i class="fa fa-id-card-o"></i> Student ID Cards</h1>
        <div class="idc-actions">
            <button class="idc-btn idc-btn-outline" onclick="idcPrintFiltered()"><i class="fa fa-print"></i> Print Filtered</button>
            <button class="idc-btn idc-btn-primary" onclick="idcPrintAll()"><i class="fa fa-print"></i> Print All</button>
        </div>
    </div>

    <div class="idc-filters no-print">
        <div class="idc-search-wrap">
            <i class="fa fa-search"></i>
            <input type="text" class="idc-search" id="idcSearch" placeholder="Search by name or Student ID...">
        </div>
        <select class="idc-select" id="filterClass">
            <option value="">All Classes</option>
            <?php foreach ($filterClasses as $c => $_): ?>
            <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
        </select>
        <select class="idc-select" id="filterSection">
            <option value="">All Sections</option>
            <?php foreach ($filterSections as $s => $_): ?>
            <option value="<?= htmlspecialchars($s) ?>"><?= htmlspecialchars($s) ?></option>
            <?php endforeach; ?>
        </select>
        <span class="idc-counter">Showing <b id="idcCount"><?= count($students) ?></b> of <?= count($students) ?></span>
    </div>

    <?php if (empty($students)): ?>
    <div class="idc-empty">
        <i class="fa fa-id-card-o"></i>
        No enrolled students found for <?= htmlspecialchars($session_year) ?>.
    </div>
    <?php else: ?>
    <div class="idc-grid" id="idcGrid">
        <?php foreach ($students as $s):
            $uid     = $s['User Id'] ?? '';
            $name    = $s['Name'] ?? '';
            // Same normalisation as the filter loop above — keeps
            // data-attrs in sync with dropdown values + the per-card
            // display label.
            $cls     = idc_with_prefix($s['Class']   ?? '', 'Class');
            $sec     = idc_with_prefix($s['Section'] ?? '', 'Section');
            $photo   = idc_photo($s);
            $initial = mb_strtoupper(mb_substr($name, 0, 1));
            $safeUid = preg_replace('/[^a-z0-9]/i', '_', $uid);
            $dob     = idc_dob($s['DOB'] ?? $s['Date of Birth'] ?? '');
            $blood   = $s['Blood Group'] ?? $s['BloodGroup'] ?? '';
            $father  = $s['Father Name'] ?? '';
            $phone   = $s['Phone Number'] ?? $s['Phone'] ?? '';
            $addrRaw = $s['Address'] ?? $s['address'] ?? '';
            $addr    = is_array($addrRaw) ? implode(', ', array_filter($addrRaw)) : (string)$addrRaw;
        ?>
        <div class="idc-card-wrap"
             data-class="<?= htmlspecialchars($cls) ?>"
             data-section="<?= htmlspecialchars($sec) ?>"
             data-name="<?= htmlspecialchars(strtolower($name)) ?>"
             data-uid="<?= htmlspecialchars(strtolower($uid)) ?>">

            <div class="idc-card" id="card-<?= $safeUid ?>">

                <!-- Header: logo left, school name + address right -->
                <div class="idc-hdr">
                    <img class="idc-hdr-logo" src="<?= htmlspecialchars($schoolLogo) ?>"
                         onerror="this.src='<?= $fallback ?>'" alt="">
                    <div class="idc-hdr-info">
                        <div class="idc-hdr-name"><?= htmlspecialchars($schoolN) ?></div>
                        <?php if ($schoolAddr): ?>
                        <div class="idc-hdr-addr"><?= htmlspecialchars(mb_strimwidth($schoolAddr, 0, 55, '...')) ?></div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Photo — below header -->
                <div class="idc-photo-area">
                    <?php if ($photo): ?>
                    <img class="idc-photo" src="<?= htmlspecialchars($photo) ?>"
                         onerror="this.style.display='none';this.nextElementSibling.style.display='flex'" alt="">
                    <div class="idc-photo-fb" style="display:none"><?= $initial ?></div>
                    <?php else: ?>
                    <div class="idc-photo-fb"><?= $initial ?></div>
                    <?php endif; ?>
                </div>

                <!-- Name + QR (replaces CODE128 barcode, 2026 redesign) -->
                <div class="idc-body">
                    <div class="idc-name"><?= htmlspecialchars($name) ?></div>

                    <?php
                    // QR is shown only when we have BOTH a studentId and the
                    // controller passed the schoolId. Without the schoolId
                    // the token is meaningless to the attendance scanner,
                    // so we'd rather print no QR than print a broken one.
                    //
                    // Inline SVG via `qr_svg_data_uri()` — entire QR is
                    // embedded as `data:image/svg+xml;base64,…` so:
                    //   - zero network calls (no ad-blocker / firewall risk)
                    //   - vector → sharp at any print size
                    //   - works offline / air-gapped
                    if ($uid && $qrSchoolId !== ''):
                        $qrToken   = qr_token_encode($qrSchoolId, $uid);
                        $qrDataUri = qr_svg_data_uri($qrToken);
                    ?>
                    <div class="idc-qr-wrap">
                        <?php if ($qrDataUri !== ''): ?>
                        <img src="<?= htmlspecialchars($qrDataUri) ?>"
                             alt="QR <?= htmlspecialchars($uid) ?>"
                             title="Scan for attendance">
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <div class="idc-class"><?= htmlspecialchars($cls) ?> &middot; <?= htmlspecialchars($sec) ?></div>
                </div>

                <!-- Info rows -->
                <div class="idc-info">
                    <?php if ($uid): ?>
                    <div class="idc-info-row">
                        <span class="idc-info-ico"><i class="fa fa-id-badge"></i></span>
                        <span class="idc-info-lbl">Student ID</span>
                        <span class="idc-info-val" style="color:var(--idc);font-weight:700"><?= htmlspecialchars($uid) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($father): ?>
                    <div class="idc-info-row">
                        <span class="idc-info-ico"><i class="fa fa-user-o"></i></span>
                        <span class="idc-info-lbl">Father</span>
                        <span class="idc-info-val"><?= htmlspecialchars($father) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($dob): ?>
                    <div class="idc-info-row">
                        <span class="idc-info-ico"><i class="fa fa-calendar"></i></span>
                        <span class="idc-info-lbl">DOB</span>
                        <span class="idc-info-val"><?= htmlspecialchars($dob) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($blood): ?>
                    <div class="idc-info-row">
                        <span class="idc-info-ico"><i class="fa fa-tint"></i></span>
                        <span class="idc-info-lbl">Blood</span>
                        <span class="idc-info-val idc-blood"><?= htmlspecialchars($blood) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($phone): ?>
                    <div class="idc-info-row">
                        <span class="idc-info-ico"><i class="fa fa-phone"></i></span>
                        <span class="idc-info-lbl">Phone</span>
                        <span class="idc-info-val idc-phone"><?= htmlspecialchars($phone) ?></span>
                    </div>
                    <?php endif; ?>

                    <?php if ($addr): ?>
                    <div class="idc-info-row">
                        <span class="idc-info-ico"><i class="fa fa-map-marker"></i></span>
                        <span class="idc-info-lbl">Address</span>
                        <span class="idc-info-val"><?= htmlspecialchars(mb_strimwidth($addr, 0, 40, '...')) ?></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Footer -->
                <div class="idc-ftr">
                    <span class="idc-ftr-label">Student ID Card</span>
                    <span class="idc-ftr-valid">Valid: <?= htmlspecialchars($session_year) ?></span>
                </div>

            </div>

            <button class="idc-print-one no-print" onclick="idcPrintOne('<?= $safeUid ?>')">
                <i class="fa fa-print"></i> Print
            </button>

        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

</div>
</div>

<script>
(function(){
    'use strict';
    var grid    = document.getElementById('idcGrid');
    var search  = document.getElementById('idcSearch');
    var fClass  = document.getElementById('filterClass');
    var fSec    = document.getElementById('filterSection');
    var counter = document.getElementById('idcCount');

    function applyFilters() {
        if (!grid) return;
        var q   = (search.value || '').toLowerCase().trim();
        var cls = fClass.value;
        var sec = fSec.value;
        var n   = 0;
        Array.prototype.forEach.call(grid.querySelectorAll('.idc-card-wrap'), function(w) {
            var ok = (!q || w.dataset.name.indexOf(q) > -1 || w.dataset.uid.indexOf(q) > -1)
                  && (!cls || w.dataset.class === cls)
                  && (!sec || w.dataset.section === sec);
            w.style.display = ok ? '' : 'none';
            if (ok) n++;
        });
        counter.textContent = n;
    }
    if (search) search.addEventListener('input', applyFilters);
    if (fClass) fClass.addEventListener('change', applyFilters);
    if (fSec)   fSec.addEventListener('change', applyFilters);

    /* ── QR codes ── No client-side init needed; QR PNGs are rendered
       server-side via qrserver.com and embedded as <img>. The legacy
       JsBarcode CDN was removed in the 2026 QR redesign. */

    /* ── Card CSS for print ── */
    function cardCSS() {
        return [
            '*{margin:0;padding:0;box-sizing:border-box}',
            'body{font-family:sans-serif}',
            ':root{--idc:#0f766e;--idc-lt:rgba(15,118,110,.1)}',
            '.idc-card{width:260px;border-radius:10px;overflow:hidden;background:#fff;border:1px solid #ccc;page-break-inside:avoid}',
            '.idc-hdr{background:var(--idc);padding:10px 12px;display:flex;align-items:center;gap:8px}',
            '.idc-hdr-logo{width:36px;height:36px;border-radius:50%;object-fit:contain;background:#fff;padding:2px;flex-shrink:0}',
            '.idc-hdr-info{flex:1;min-width:0}',
            '.idc-hdr-name{color:#fff;font-size:.74rem;font-weight:700;line-height:1.25}',
            '.idc-hdr-addr{color:rgba(255,255,255,.72);font-size:.6rem;line-height:1.3;margin-top:1px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}',
            '.idc-photo-area{display:flex;justify-content:center;padding:12px 0 6px;background:#fff}',
            '.idc-photo{width:72px;height:72px;border-radius:50%;object-fit:cover;border:3px solid var(--idc);box-shadow:0 2px 8px rgba(0,0,0,.1);background:#f3f4f6}',
            '.idc-photo-fb{width:72px;height:72px;border-radius:50%;display:flex;align-items:center;justify-content:center;border:3px solid var(--idc);background:var(--idc-lt);color:var(--idc);font-size:1.6rem;font-weight:700}',
            '.idc-body{padding:4px 14px 10px;text-align:center}',
            '.idc-name{font-size:.92rem;font-weight:700;color:#111;line-height:1.3;margin-bottom:1px;word-break:break-word}',
            '.idc-class{font-size:.72rem;color:#6b7280;margin-bottom:6px;font-weight:500}',
            '.idc-sid{display:inline-flex;align-items:center;gap:5px;padding:4px 12px;background:var(--idc-lt);border:1.5px solid var(--idc);border-radius:18px;margin-bottom:8px}',
            '.idc-sid-label{font-size:.58rem;color:var(--idc);font-weight:600;text-transform:uppercase;letter-spacing:.5px}',
            '.idc-sid-value{font-size:.78rem;color:var(--idc);font-weight:800;letter-spacing:.3px}',
            '.idc-qr-wrap{display:flex;justify-content:center;margin:4px 0 6px}',
            '.idc-qr-wrap img{width:76px;height:76px}',
            '.idc-info{padding:0 14px 8px}',
            '.idc-info-row{display:flex;align-items:center;gap:6px;padding:3px 0;border-bottom:1px solid #e5e7eb;font-size:.73rem}',
            '.idc-info-row:last-child{border-bottom:none}',
            '.idc-info-ico{color:var(--idc);width:14px;text-align:center;flex-shrink:0;font-size:.7rem}',
            '.idc-info-lbl{color:#9ca3af;min-width:48px;font-size:.68rem}',
            '.idc-info-val{color:#111;font-weight:600;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
            '.idc-info-val.idc-blood{color:#dc2626}',
            '.idc-info-val.idc-phone{color:#2563eb}',
            '.idc-ftr{background:var(--idc);padding:6px 12px;display:flex;align-items:center;justify-content:space-between}',
            '.idc-ftr-label{font-size:.6rem;color:rgba(255,255,255,.9);font-weight:600;text-transform:uppercase;letter-spacing:.5px}',
            '.idc-ftr-valid{font-size:.58rem;color:rgba(255,255,255,.65)}',
            '.idc-print-one{display:none}',
        ].join('\n');
    }

    window.idcPrintOne = function(safeUid) {
        var el = document.getElementById('card-' + safeUid);
        if (!el) return;
        openPrint([el.outerHTML]);
    };

    window.idcPrintFiltered = function() {
        if (!grid) return;
        var cards = [];
        grid.querySelectorAll('.idc-card-wrap').forEach(function(w) {
            if (w.style.display !== 'none') {
                var c = w.querySelector('.idc-card');
                if (c) cards.push(c.outerHTML);
            }
        });
        if (!cards.length) return alert('No cards to print.');
        openPrint(cards);
    };

    window.idcPrintAll = function() {
        if (!grid) return;
        var cards = [];
        grid.querySelectorAll('.idc-card').forEach(function(c) { cards.push(c.outerHTML); });
        if (!cards.length) return alert('No cards to print.');
        openPrint(cards);
    };

    function openPrint(cards) {
        var w = window.open('', '_blank');
        w.document.write(
            '<!DOCTYPE html><html><head><title>Student ID Cards</title>' +
            '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">' +
            '<style>' + cardCSS() +
            '.pg{display:grid;grid-template-columns:repeat(3,260px);gap:8mm;padding:10mm;justify-content:center}' +
            '@media print{body{margin:0}.pg{gap:6mm;padding:8mm}}' +
            '</style></head><body><div class="pg">' + cards.join('') + '</div></body></html>'
        );
        w.document.close();
        w.focus();
        setTimeout(function(){ w.print(); }, 500);
    }
})();
</script>
