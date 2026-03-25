<?php
defined('BASEPATH') or exit('No direct script access allowed');

/* ── Helpers ─────────────────────────────────────────────────────── */
function tic_photo(array $t): string {
    if (!empty($t['Doc']['Photo']['url']))  return trim($t['Doc']['Photo']['url']);
    if (!empty($t['ProfilePic']))           return trim($t['ProfilePic']);
    if (!empty($t['Photo URL']))            return trim($t['Photo URL']);
    return '';
}

function tic_date(string $raw): string {
    if (!$raw) return '—';
    foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw);
        if ($dt) return $dt->format('d M Y');
    }
    return $raw;
}

$staff        = $staff        ?? [];
$session_year = $session_year ?? '';
$school_name  = $school_name  ?? 'School';
$school_logo  = $school_logo  ?? '';

/* Pre-compute unique departments & positions for filter dropdowns */
$filterDepts = [];
$filterPosns = [];
foreach ($staff as $t) {
    $d = trim($t['Department'] ?? '');
    $p = trim($t['Position']   ?? '');
    if ($d) $filterDepts[$d] = true;
    if ($p) $filterPosns[$p] = true;
}
ksort($filterDepts);
ksort($filterPosns);
?>

<div class="content-wrapper">
<div class="ic-wrap">

  <!-- ══ TOP BAR ═══════════════════════════════════════════════════ -->
  <div class="ic-topbar">
    <div class="ic-topbar-left">
      <h1 class="ic-page-title">
        <i class="fa fa-id-card-o"></i> Teacher ID Cards
      </h1>
      <ol class="ic-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('staff/all_staff') ?>">Staff</a></li>
        <li>ID Cards</li>
      </ol>
    </div>
    <div class="ic-topbar-right">
      <button class="ic-btn ic-btn-ghost" onclick="icPrintAll()">
        <i class="fa fa-print"></i> Print All
      </button>
    </div>
  </div>

  <!-- ══ FILTER BAR ════════════════════════════════════════════════ -->
  <div class="ic-filter-bar">
    <div class="ic-search-wrap">
      <i class="fa fa-search ic-search-ico"></i>
      <input type="text" id="icSearch" class="ic-search-input"
             placeholder="Search by name, ID or department…">
    </div>

    <select id="icDept" class="ic-select">
      <option value="">All Departments</option>
      <?php foreach (array_keys($filterDepts) as $d): ?>
      <option value="<?= htmlspecialchars($d) ?>"><?= htmlspecialchars($d) ?></option>
      <?php endforeach; ?>
    </select>

    <select id="icPosn" class="ic-select">
      <option value="">All Positions</option>
      <?php foreach (array_keys($filterPosns) as $p): ?>
      <option value="<?= htmlspecialchars($p) ?>"><?= htmlspecialchars($p) ?></option>
      <?php endforeach; ?>
    </select>

    <span class="ic-counter" id="icCounter">
      <i class="fa fa-users"></i>
      <span id="icCount"><?= count($staff) ?></span> teachers
    </span>
  </div>

  <!-- ══ CARDS GRID ════════════════════════════════════════════════ -->
  <?php if (empty($staff)): ?>
  <div class="ic-empty">
    <i class="fa fa-id-card-o"></i>
    <p>No teachers assigned in session <strong><?= htmlspecialchars($session_year) ?></strong>.</p>
    <a href="<?= base_url('staff/new_staff') ?>" class="ic-btn ic-btn-primary">
      <i class="fa fa-plus"></i> Add Teacher
    </a>
  </div>
  <?php else: ?>

  <div class="ic-grid" id="icGrid">
  <?php foreach ($staff as $t):
    $photo   = tic_photo($t);
    $name    = htmlspecialchars($t['Name']            ?? '',  ENT_QUOTES, 'UTF-8');
    $uid     = htmlspecialchars($t['User ID']         ?? '',  ENT_QUOTES, 'UTF-8');
    $dept    = htmlspecialchars(trim($t['Department'] ?? ''), ENT_QUOTES, 'UTF-8');
    $posn    = htmlspecialchars(trim($t['Position']   ?? ''), ENT_QUOTES, 'UTF-8');
    $bloodGrp= htmlspecialchars(trim($t['Blood Group'] ?? $t['blood_group'] ?? ''), ENT_QUOTES, 'UTF-8');
    $phone   = htmlspecialchars($t['Phone Number']    ?? '—', ENT_QUOTES, 'UTF-8');
    $dob     = tic_date($t['DOB'] ?? $t['Date of Birth'] ?? '');
    $gender  = htmlspecialchars($t['Gender']          ?? '',  ENT_QUOTES, 'UTF-8');
    $joining = tic_date($t['Date Of Joining']         ?? '');
    $initial = mb_strtoupper(mb_substr($t['Name'] ?? 'T', 0, 1));
    $safeUid = preg_replace('/[^a-z0-9]/i', '_', $t['User ID'] ?? 'x');
    $logoUrl = htmlspecialchars($school_logo, ENT_QUOTES, 'UTF-8');
  ?>
  <div class="ic-card-wrap"
       data-name="<?= mb_strtolower($name) ?>"
       data-uid="<?= mb_strtolower($uid) ?>"
       data-dept="<?= mb_strtolower($dept) ?>"
       data-posn="<?= mb_strtolower($posn) ?>">

    <!-- ─── THE PHYSICAL ID CARD ─────────────────────────────── -->
    <div class="ic-card" id="card-<?= $safeUid ?>">

      <!-- Header band -->
      <div class="ic-card-hd">
        <div class="ic-hd-pattern"></div>
        <div class="ic-hd-content">
          <?php if ($logoUrl): ?>
            <img src="<?= $logoUrl ?>" alt="" class="ic-logo-img"
                 onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
            <div class="ic-logo-circle" style="display:none"><?= $initial ?></div>
          <?php else: ?>
            <div class="ic-logo-circle"><?= $initial ?></div>
          <?php endif; ?>
          <div class="ic-hd-text">
            <div class="ic-school-nm"><?= htmlspecialchars($school_name) ?></div>
            <div class="ic-session-nm"><?= htmlspecialchars($session_year) ?></div>
          </div>
        </div>
      </div>

      <!-- Photo -->
      <div class="ic-photo-ring">
        <?php if ($photo): ?>
          <img src="<?= htmlspecialchars($photo) ?>" alt="<?= $name ?>"
               class="ic-photo"
               onerror="this.style.display='none';this.nextElementSibling.style.display='flex'">
          <div class="ic-photo-fb" style="display:none"><?= $initial ?></div>
        <?php else: ?>
          <div class="ic-photo-fb"><?= $initial ?></div>
        <?php endif; ?>
      </div>

      <!-- Name & role badges -->
      <div class="ic-staff-nm"><?= $name ?></div>

      <!-- Barcode -->
      <div class="ic-barcode-wrap">
        <svg class="ic-barcode" data-barcode="<?= $uid ?>"></svg>
      </div>

      <div class="ic-badges">
        <?php if ($dept):     ?><span class="ic-badge ic-badge-dept"><?= $dept ?></span><?php endif; ?>
        <?php if ($posn):     ?><span class="ic-badge ic-badge-posn"><?= $posn ?></span><?php endif; ?>
        <?php if ($bloodGrp): ?><span class="ic-badge ic-badge-blood"><i class="fa fa-tint"></i> <?= $bloodGrp ?></span><?php endif; ?>
      </div>

      <!-- Divider -->
      <div class="ic-divider"></div>

      <!-- Info rows -->
      <div class="ic-info">
        <div class="ic-info-row">
          <span class="ic-info-ico"><i class="fa fa-id-badge"></i></span>
          <span class="ic-info-lbl">ID</span>
          <span class="ic-info-val ic-uid"><?= $uid ?></span>
        </div>
        <div class="ic-info-row">
          <span class="ic-info-ico"><i class="fa fa-phone"></i></span>
          <span class="ic-info-lbl">Phone</span>
          <span class="ic-info-val ic-phone"><?= $phone ?></span>
        </div>
        <div class="ic-info-row">
          <span class="ic-info-ico"><i class="fa fa-calendar"></i></span>
          <span class="ic-info-lbl">DOB</span>
          <span class="ic-info-val"><?= $dob ?></span>
        </div>
        <div class="ic-info-row">
          <span class="ic-info-ico"><i class="fa fa-sign-in"></i></span>
          <span class="ic-info-lbl">Joined</span>
          <span class="ic-info-val"><?= $joining ?></span>
        </div>
        <?php if ($gender): ?>
        <div class="ic-info-row">
          <span class="ic-info-ico"><i class="fa fa-venus-mars"></i></span>
          <span class="ic-info-lbl">Gender</span>
          <span class="ic-info-val"><?= $gender ?></span>
        </div>
        <?php endif; ?>
      </div>

      <!-- Footer band -->
      <div class="ic-card-ft">
        <span class="ic-ft-title">TEACHER ID CARD</span>
        <span class="ic-ft-valid">Valid: <?= htmlspecialchars($session_year) ?></span>
      </div>

    </div><!-- /.ic-card -->

    <!-- Print action below card -->
    <button class="ic-print-btn"
            onclick="icPrintOne('<?= $safeUid ?>','<?= addslashes($name) ?>','<?= addslashes($school_name) ?>','<?= addslashes($session_year) ?>')">
      <i class="fa fa-print"></i> Print
    </button>

  </div><!-- /.ic-card-wrap -->
  <?php endforeach; ?>
  </div><!-- /#icGrid -->

  <!-- No-result message (shown by JS) -->
  <div id="icNoResult" class="ic-empty" style="display:none">
    <i class="fa fa-search"></i>
    <p>No teachers match your filters.</p>
  </div>

  <?php endif; ?>

</div><!-- /.ic-wrap -->
</div><!-- /.content-wrapper -->


<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
/* ══════════════════════════════════════════════════════════════════
   Teacher ID Card — Filter & Print Logic
══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var grid      = document.getElementById('icGrid');
  var noResult  = document.getElementById('icNoResult');
  var countEl   = document.getElementById('icCount');
  var searchIn  = document.getElementById('icSearch');
  var deptSel   = document.getElementById('icDept');
  var posnSel   = document.getElementById('icPosn');

  function applyFilters() {
    if (!grid) return;
    var q    = (searchIn.value || '').toLowerCase().trim();
    var dept = deptSel.value.toLowerCase();
    var posn = posnSel.value.toLowerCase();
    var visible = 0;

    Array.prototype.forEach.call(grid.children, function (wrap) {
      var matchQ = !q || (
        (wrap.dataset.name || '').indexOf(q) > -1 ||
        (wrap.dataset.uid  || '').indexOf(q) > -1 ||
        (wrap.dataset.dept || '').indexOf(q) > -1
      );
      var matchD = !dept || (wrap.dataset.dept || '') === dept;
      var matchP = !posn || (wrap.dataset.posn || '') === posn;

      var show = matchQ && matchD && matchP;
      wrap.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    countEl.textContent = visible;
    if (noResult) noResult.style.display = (visible === 0 && grid.children.length > 0) ? 'block' : 'none';
  }

  if (searchIn) searchIn.addEventListener('input',  applyFilters);
  if (deptSel)  deptSel.addEventListener('change',  applyFilters);
  if (posnSel)  posnSel.addEventListener('change',  applyFilters);

  /* ── Print one card ──────────────────────────────────────────── */
  window.icPrintOne = function (safeUid, name, school, session) {
    var cardEl = document.getElementById('card-' + safeUid);
    if (!cardEl) return;

    var w = window.open('', '_blank', 'width=480,height=700');
    w.document.write(
      '<!DOCTYPE html><html><head><meta charset="UTF-8">' +
      '<title>ID Card \u2014 ' + name + '</title>' +
      '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">' +
      '<style>' + getCardCSS(true) + '</style>' +
      '</head><body class="ic-print-body">' +
      '<div class="ic-print-page">' + cardEl.outerHTML + '</div>' +
      '</body></html>'
    );
    w.document.close();
    w.focus();
    setTimeout(function () { w.print(); }, 600);
  };

  /* ── Print all visible cards ─────────────────────────────────── */
  window.icPrintAll = function () {
    if (!grid) return;
    var cards = [];
    Array.prototype.forEach.call(grid.children, function (wrap) {
      if (wrap.style.display === 'none') return;
      var cardEl = wrap.querySelector('.ic-card');
      if (cardEl) cards.push(cardEl.outerHTML);
    });
    if (!cards.length) { alert('No cards to print.'); return; }

    var w = window.open('', '_blank', 'width=900,height=700');
    w.document.write(
      '<!DOCTYPE html><html><head><meta charset="UTF-8">' +
      '<title>Teacher ID Cards</title>' +
      '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css">' +
      '<style>' + getCardCSS(true) +
      '.ic-print-page{display:grid;grid-template-columns:repeat(3,1fr);gap:8mm;padding:10mm;}' +
      '@media print{body{margin:0}.ic-print-page{gap:6mm;padding:8mm;}}' +
      '</style>' +
      '</head><body>' +
      '<div class="ic-print-page">' + cards.join('') + '</div>' +
      '</body></html>'
    );
    w.document.close();
    w.focus();
    setTimeout(function () { w.print(); }, 800);
  };

  /* ── Initialize barcodes ─────────────────────────────────────── */
  function initBarcodes(root) {
    var svgs = (root || document).querySelectorAll('.ic-barcode[data-barcode]');
    if (typeof JsBarcode === 'undefined') return;
    svgs.forEach(function(svg) {
      var code = svg.getAttribute('data-barcode');
      if (!code) return;
      try {
        JsBarcode(svg, code, {
          format: 'CODE128',
          width: 1.2,
          height: 28,
          displayValue: false,
          margin: 0,
          background: 'transparent'
        });
      } catch(e) { /* silently skip invalid codes */ }
    });
  }
  // Init barcodes on page load
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { initBarcodes(); });
  } else {
    initBarcodes();
  }

  /* ── Card CSS (screen card + print window) ───────────────────── */
  function getCardCSS(forPrint) {
    return [
      '.ic-card{width:280px;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.18);font-family:Arial,sans-serif;background:#fff;display:flex;flex-direction:column;align-items:center;}',
      '.ic-card-hd{width:100%;background:linear-gradient(135deg,#0f766e 0%,#134e4a 100%);padding:10px 12px;position:relative;overflow:hidden;box-sizing:border-box;}',
      '.ic-hd-pattern{position:absolute;inset:0;background:repeating-linear-gradient(45deg,rgba(255,255,255,.04) 0,rgba(255,255,255,.04) 1px,transparent 1px,transparent 8px);}',
      '.ic-hd-content{position:relative;display:flex;align-items:center;gap:8px;}',
      '.ic-logo-circle{width:34px;height:34px;border-radius:50%;background:rgba(255,255,255,.18);border:1.5px solid rgba(255,255,255,.5);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.7rem;font-weight:700;letter-spacing:.05em;flex-shrink:0;}',
      '.ic-logo-img{width:34px;height:34px;border-radius:50%;object-fit:cover;border:1.5px solid rgba(255,255,255,.5);flex-shrink:0;background:#fff;}',
      '.ic-school-nm{color:#fff;font-size:.82rem;font-weight:700;letter-spacing:.04em;line-height:1.25;overflow:hidden;text-overflow:ellipsis;max-width:200px;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;white-space:normal;}',
      '.ic-session-nm{color:rgba(255,255,255,.75);font-size:.65rem;margin-top:2px;}',
      '.ic-photo-ring{width:80px;height:80px;border-radius:50%;border:3px solid #0f766e;box-shadow:0 2px 12px rgba(15,118,110,.3);margin-top:10px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#e6f4f1;flex-shrink:0;}',
      '.ic-photo{width:100%;height:100%;object-fit:cover;}',
      '.ic-photo-fb{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:700;color:#0f766e;background:#e6f4f1;}',
      '.ic-staff-nm{font-size:.9rem;font-weight:700;color:#111;text-align:center;margin:7px 10px 3px;line-height:1.3;}',
      '.ic-barcode-wrap{width:85%;text-align:center;margin:2px auto 0;padding:0 4px;}',
      '.ic-barcode{width:100%;height:24px;}',
      '.ic-badges{display:flex;flex-wrap:wrap;justify-content:center;gap:4px;margin-bottom:5px;padding:0 8px;}',
      '.ic-badge{font-size:.62rem;font-weight:700;padding:2px 7px;border-radius:20px;letter-spacing:.04em;text-transform:uppercase;}',
      '.ic-badge-dept{background:#ccfbf1;color:#0f766e;}',
      '.ic-badge-posn{background:#d1fae5;color:#065f46;}',
      '.ic-badge-blood{background:#fee2e2;color:#dc2626;}',
      '.ic-divider{width:85%;height:1px;background:linear-gradient(90deg,transparent,#0f766e55,transparent);margin:2px 0 5px;}',
      '.ic-info{width:100%;padding:0 14px 8px;box-sizing:border-box;}',
      '.ic-info-row{display:flex;align-items:center;gap:5px;padding:3px 0;border-bottom:1px solid #f0f9f8;}',
      '.ic-info-row:last-child{border-bottom:none;}',
      '.ic-info-ico{color:#0f766e;font-size:.72rem;width:14px;text-align:center;flex-shrink:0;}',
      '.ic-info-lbl{font-size:.65rem;color:#6b7280;font-weight:600;width:40px;flex-shrink:0;text-transform:uppercase;letter-spacing:.03em;}',
      '.ic-info-val{font-size:.72rem;color:#1f2937;font-weight:500;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ic-uid{color:#0f766e;font-weight:700;}',
      '.ic-phone{color:#0369a1;font-weight:600;}',
      '.ic-card-ft{width:100%;background:linear-gradient(135deg,#0f766e,#134e4a);padding:6px 12px;display:flex;justify-content:space-between;align-items:center;margin-top:auto;box-sizing:border-box;}',
      '.ic-ft-title{color:#fff;font-size:.62rem;font-weight:700;letter-spacing:.1em;}',
      '.ic-ft-valid{color:rgba(255,255,255,.75);font-size:.58rem;}',
      forPrint ? '.ic-print-body{margin:0;padding:0;background:#fff;}' : ''
    ].join('');
  }

})();
</script>


<style>
/* ═══════════════════════════════════════════════════════════════
   Teacher ID Card Page — teal / navy global theme
═══════════════════════════════════════════════════════════════ */

.ic-wrap {
  max-width: 1200px;
  margin: 0 auto;
  padding: 24px 16px 56px;
}

/* ── Top bar ─────────────────────────────────────────────────── */
.ic-topbar {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  margin-bottom: 20px;
}
.ic-page-title {
  font-size: 1.7rem;
  font-weight: 700;
  color: var(--t1);
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0 0 6px;
}
.ic-page-title i { color: var(--gold); }

.ic-breadcrumb {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  gap: 6px;
  font-size: .95rem;
  color: var(--t3);
}
.ic-breadcrumb li + li::before { content: '›'; margin-right: 6px; }
.ic-breadcrumb a { color: var(--gold); text-decoration: none; }
.ic-breadcrumb a:hover { text-decoration: underline; }

.ic-topbar-right { display: flex; align-items: center; gap: 10px; }

/* ── Buttons ─────────────────────────────────────────────────── */
.ic-btn {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 22px;
  border-radius: 8px;
  font-size: .95rem;
  font-weight: 600;
  cursor: pointer;
  border: none;
  text-decoration: none;
  transition: background .18s, opacity .18s, transform .1s;
}
.ic-btn:active { transform: scale(.97); }
.ic-btn-primary { background: var(--gold); color: #fff; }
.ic-btn-primary:hover { background: var(--gold2); color: #fff; }
.ic-btn-ghost {
  background: transparent;
  color: var(--gold);
  border: 1.5px solid var(--gold);
}
.ic-btn-ghost:hover { background: var(--gold-dim); }

/* ── Filter bar ──────────────────────────────────────────────── */
.ic-filter-bar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 14px 18px;
  margin-bottom: 24px;
  box-shadow: var(--sh);
}

.ic-search-wrap {
  position: relative;
  flex: 1;
  min-width: 220px;
}
.ic-search-ico {
  position: absolute;
  left: 12px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--t3);
  font-size: .95rem;
  pointer-events: none;
}
.ic-search-input {
  width: 100%;
  padding: 10px 14px 10px 36px;
  border: 1px solid var(--border);
  border-radius: 7px;
  background: var(--bg3);
  color: var(--t1);
  font-size: .95rem;
  box-sizing: border-box;
  transition: border-color .18s, box-shadow .18s;
}
.ic-search-input:focus {
  outline: none;
  border-color: var(--gold);
  box-shadow: 0 0 0 3px var(--gold-ring);
}

.ic-select {
  padding: 10px 14px;
  border: 1px solid var(--border);
  border-radius: 7px;
  background: var(--bg3);
  color: var(--t1);
  font-size: .95rem;
  cursor: pointer;
  min-width: 160px;
}
.ic-select:focus { outline: none; border-color: var(--gold); }

.ic-counter {
  display: flex;
  align-items: center;
  gap: 5px;
  font-size: .85rem;
  font-weight: 600;
  color: var(--gold);
  margin-left: auto;
  white-space: nowrap;
}

/* ── Cards grid ──────────────────────────────────────────────── */
.ic-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
  gap: 30px;
  justify-items: center;
}

/* ── Card wrapper (holds card + print btn) ───────────────────── */
.ic-card-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

/* ── THE ID CARD ─────────────────────────────────────────────── */
.ic-card {
  width: 270px;
  border-radius: 14px;
  overflow: hidden;
  box-shadow: 0 6px 28px rgba(0, 0, 0, .14);
  background: var(--bg2);
  display: flex;
  flex-direction: column;
  align-items: center;
  transition: transform .22s, box-shadow .22s;
  position: relative;
}
.ic-card:hover {
  transform: translateY(-4px) scale(1.015);
  box-shadow: 0 14px 36px rgba(15, 118, 110, .22);
}

/* Card header band */
.ic-card-hd {
  width: 100%;
  background: linear-gradient(135deg, var(--gold) 0%, #134e4a 100%);
  padding: 12px 14px;
  position: relative;
  overflow: hidden;
  box-sizing: border-box;
}
.ic-hd-pattern {
  position: absolute;
  inset: 0;
  background: repeating-linear-gradient(
    45deg,
    rgba(255,255,255,.04) 0,
    rgba(255,255,255,.04) 1px,
    transparent 1px,
    transparent 8px
  );
  pointer-events: none;
}
.ic-hd-content {
  position: relative;
  display: flex;
  align-items: center;
  gap: 8px;
}
.ic-logo-circle {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  background: rgba(255,255,255,.18);
  border: 1.5px solid rgba(255,255,255,.5);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: .85rem;
  font-weight: 700;
  letter-spacing: .05em;
  flex-shrink: 0;
}
.ic-logo-img {
  width: 42px;
  height: 42px;
  border-radius: 50%;
  object-fit: cover;
  border: 1.5px solid rgba(255,255,255,.5);
  flex-shrink: 0;
  background: #fff;
}
.ic-school-nm {
  color: #fff;
  font-size: 1rem;
  font-weight: 700;
  letter-spacing: .04em;
  line-height: 1.25;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 220px;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  white-space: normal;
}
.ic-session-nm {
  color: rgba(255,255,255,.75);
  font-size: .8rem;
  margin-top: 2px;
}

/* Photo ring */
.ic-photo-ring {
  width: 96px;
  height: 96px;
  border-radius: 50%;
  border: 3px solid var(--gold);
  box-shadow: 0 2px 14px rgba(15,118,110,.28);
  margin-top: -14px;
  overflow: hidden;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bg3);
  flex-shrink: 0;
  position: relative;
  z-index: 1;
}
.ic-photo {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}
.ic-photo-fb {
  width: 100%;
  height: 100%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 1.7rem;
  font-weight: 700;
  color: var(--gold);
  background: var(--gold-dim);
}

/* Name */
.ic-staff-nm {
  font-size: 1.15rem;
  font-weight: 700;
  color: var(--t1);
  text-align: center;
  margin: 10px 12px 5px;
  line-height: 1.3;
}

/* Barcode */
.ic-barcode-wrap {
  width: 85%;
  text-align: center;
  margin: 2px auto 0;
  padding: 0 4px;
}
.ic-barcode {
  width: 100%;
  height: 28px;
}
/* Background rect = transparent; bar rects inside <g> = dark */
.ic-barcode > rect { fill: transparent !important; }
.ic-barcode g rect { fill: #1e293b !important; }
[data-theme="night"] .ic-barcode g rect { fill: var(--t1) !important; }

/* Badges */
.ic-badges {
  display: flex;
  flex-wrap: wrap;
  justify-content: center;
  gap: 4px;
  padding: 0 8px 6px;
}
.ic-badge {
  font-size: .78rem;
  font-weight: 700;
  padding: 3px 10px;
  border-radius: 20px;
  letter-spacing: .04em;
  text-transform: uppercase;
}
.ic-badge-dept  { background: var(--gold-dim);             color: var(--gold); }
.ic-badge-posn  { background: rgba(5,150,105,.12);         color: #059669; }
.ic-badge-blood { background: rgba(239,68,68,.1);          color: #dc2626; }

/* Divider */
.ic-divider {
  width: 85%;
  height: 1px;
  background: linear-gradient(90deg, transparent, var(--gold-ring), transparent);
  margin: 2px 0 6px;
}

/* Info rows */
.ic-info {
  width: 100%;
  padding: 0 13px 10px;
  box-sizing: border-box;
}
.ic-info-row {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 3.5px 0;
  border-bottom: 1px dashed var(--border);
}
.ic-info-row:last-child { border-bottom: none; }
.ic-info-ico {
  color: var(--gold);
  font-size: .9rem;
  width: 18px;
  text-align: center;
  flex-shrink: 0;
}
.ic-info-lbl {
  font-size: .8rem;
  color: var(--t3);
  font-weight: 600;
  width: 48px;
  flex-shrink: 0;
  text-transform: uppercase;
  letter-spacing: .03em;
}
.ic-info-val {
  font-size: .9rem;
  color: var(--t1);
  font-weight: 500;
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ic-uid   { color: var(--gold);  font-weight: 700; }
.ic-phone { color: #0369a1; font-weight: 600; }

/* Card footer band */
.ic-card-ft {
  width: 100%;
  background: linear-gradient(135deg, var(--gold), #134e4a);
  padding: 6px 12px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  box-sizing: border-box;
  margin-top: auto;
}
.ic-ft-title {
  color: #fff;
  font-size: .78rem;
  font-weight: 700;
  letter-spacing: .1em;
}
.ic-ft-valid {
  color: rgba(255,255,255,.75);
  font-size: .74rem;
}

/* ── Print button below each card ────────────────────────────── */
.ic-print-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 6px 18px;
  background: transparent;
  border: 1.5px solid var(--gold);
  border-radius: 20px;
  color: var(--gold);
  font-size: .8rem;
  font-weight: 600;
  cursor: pointer;
  transition: background .18s, color .18s;
}
.ic-print-btn:hover {
  background: var(--gold);
  color: #fff;
}

/* ── Empty state ─────────────────────────────────────────────── */
.ic-empty {
  text-align: center;
  padding: 64px 24px;
  color: var(--t3);
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 16px;
}
.ic-empty i { font-size: 3rem; color: var(--gold-dim); display: block; }
.ic-empty p { font-size: 1rem; margin: 0; }

/* ── Responsive ──────────────────────────────────────────────── */
@media (max-width: 600px) {
  .ic-grid { grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); gap: 18px; }
  .ic-card { width: 100%; max-width: 260px; }
  .ic-topbar { flex-direction: column; }
}
@media (max-width: 380px) {
  .ic-grid { grid-template-columns: 1fr; }
}
</style>
