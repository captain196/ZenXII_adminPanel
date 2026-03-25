<?php
defined('BASEPATH') or exit('No direct script access allowed');

/* ── Helpers ─────────────────────────────────────────────────────── */
function ic_photo(array $s): string {
    $url = trim($s['Profile Pic'] ?? $s['ProfilePic'] ?? $s['Photo URL'] ?? '');
    return $url;
}

function ic_dob(string $raw): string {
    if (!$raw) return '—';
    foreach (['Y-m-d', 'd-m-Y', 'd/m/Y', 'm/d/Y'] as $fmt) {
        $dt = DateTime::createFromFormat($fmt, $raw);
        if ($dt) return $dt->format('d M Y');
    }
    return $raw;
}

$students     = $students     ?? [];
$session_year = $session_year ?? '';
// FIXED: use display name from school_profile instead of raw Firebase key (SCH_XXXXXX)
$school_name  = $school_profile['school_name'] ?? $school_name ?? 'School';

/* Pre-compute unique classes & sections for filter dropdowns */
$filterClasses  = [];
$filterSections = [];
foreach ($students as $s) {
    $c   = trim($s['Class']   ?? '');
    $sec = trim($s['Section'] ?? '');
    if ($c)   $filterClasses[$c]    = true;
    if ($sec) $filterSections[$sec] = true;
}
ksort($filterClasses);
ksort($filterSections);
?>

<div class="content-wrapper">
<div class="ic-wrap">

  <!-- ══ TOP BAR ═══════════════════════════════════════════════════ -->
  <div class="ic-topbar">
    <div class="ic-topbar-left">
      <h1 class="ic-page-title">
        <i class="fa fa-id-card-o"></i> Student ID Cards
      </h1>
      <ol class="ic-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li>Students</li>
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
             placeholder="Search by name, ID or father's name…">
    </div>

    <select id="icClass" class="ic-select">
      <option value="">All Classes</option>
      <?php foreach (array_keys($filterClasses) as $c): ?>
      <option value="<?= htmlspecialchars($c) ?>">Class <?= htmlspecialchars($c) ?></option>
      <?php endforeach; ?>
    </select>

    <select id="icSection" class="ic-select">
      <option value="">All Sections</option>
      <?php foreach (array_keys($filterSections) as $sec): ?>
      <option value="<?= htmlspecialchars($sec) ?>">Section <?= htmlspecialchars($sec) ?></option>
      <?php endforeach; ?>
    </select>

    <span class="ic-counter" id="icCounter">
      <i class="fa fa-users"></i>
      <span id="icCount"><?= count($students) ?></span> students
    </span>
  </div>

  <!-- ══ CARDS GRID ════════════════════════════════════════════════ -->
  <?php if (empty($students)): ?>
  <div class="ic-empty">
    <i class="fa fa-id-card-o"></i>
    <p>No students enrolled in session <strong><?= htmlspecialchars($session_year) ?></strong>.</p>
    <a href="<?= base_url('student/studentAdmission') ?>" class="ic-btn ic-btn-primary">
      <i class="fa fa-plus"></i> Add Student
    </a>
  </div>
  <?php else: ?>

  <div class="ic-grid" id="icGrid">
  <?php foreach ($students as $s):
    $photo   = ic_photo($s);
    $name    = htmlspecialchars($s['Name']        ?? '',  ENT_QUOTES, 'UTF-8');
    $uid     = htmlspecialchars($s['User Id']     ?? '',  ENT_QUOTES, 'UTF-8');
    $class   = htmlspecialchars(trim($s['Class']  ?? ''), ENT_QUOTES, 'UTF-8');
    $section = htmlspecialchars(trim($s['Section']?? ''), ENT_QUOTES, 'UTF-8');
    $dob     = ic_dob($s['DOB'] ?? $s['Date of Birth'] ?? '');
    $blood     = htmlspecialchars($s['Blood Group']  ?? '—', ENT_QUOTES, 'UTF-8');
    $father    = htmlspecialchars($s['Father Name']  ?? '—', ENT_QUOTES, 'UTF-8');
    $emergency = htmlspecialchars($s['Guard Contact'] ?? $s['Phone Number'] ?? '—', ENT_QUOTES, 'UTF-8');
    $gender    = htmlspecialchars($s['Gender']       ?? '',  ENT_QUOTES, 'UTF-8');
    $initial = mb_strtoupper(mb_substr($s['Name'] ?? 'S', 0, 1));
    $safeUid = preg_replace('/[^a-z0-9]/i', '_', $s['User Id'] ?? 'x');

    /* School abbreviation: first 2 letters of each word, max 3 chars */
    $abbr = '';
    foreach (explode(' ', $school_name) as $w) {
        $abbr .= mb_strtoupper(mb_substr($w, 0, 1));
    }
    $abbr = mb_substr($abbr, 0, 3);
  ?>
  <div class="ic-card-wrap"
       data-name="<?= htmlspecialchars(mb_strtolower($name), ENT_QUOTES, 'UTF-8') ?>"
       data-uid="<?= htmlspecialchars(mb_strtolower($uid), ENT_QUOTES, 'UTF-8') ?>"
       data-father="<?= htmlspecialchars(mb_strtolower($father), ENT_QUOTES, 'UTF-8') ?>"
       data-class="<?= htmlspecialchars($class, ENT_QUOTES, 'UTF-8') ?>"
       data-section="<?= htmlspecialchars($section, ENT_QUOTES, 'UTF-8') ?>">

    <!-- ─── THE PHYSICAL ID CARD ─────────────────────────────── -->
    <div class="ic-card" id="card-<?= $safeUid ?>">

      <!-- Header band -->
      <div class="ic-card-hd">
        <div class="ic-hd-pattern"></div>
        <div class="ic-hd-content">
          <div class="ic-logo-circle"><?= $abbr ?></div>
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

      <!-- Name & barcode -->
      <div class="ic-student-nm"><?= $name ?></div>

      <!-- Barcode -->
      <div class="ic-barcode-wrap">
        <svg class="ic-barcode" data-barcode="<?= $uid ?>"></svg>
      </div>

      <div class="ic-badges">
        <?php if ($class):   ?><span class="ic-badge ic-badge-cls">Class <?= $class ?></span><?php endif; ?>
        <?php if ($section): ?><span class="ic-badge ic-badge-sec">Sec <?= $section ?></span><?php endif; ?>
        <?php if ($gender):  ?><span class="ic-badge ic-badge-gen"><?= $gender ?></span><?php endif; ?>
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
          <span class="ic-info-ico"><i class="fa fa-calendar"></i></span>
          <span class="ic-info-lbl">DOB</span>
          <span class="ic-info-val"><?= htmlspecialchars($dob, ENT_QUOTES, 'UTF-8') ?></span>
        </div>
        <div class="ic-info-row">
          <span class="ic-info-ico"><i class="fa fa-tint"></i></span>
          <span class="ic-info-lbl">Blood</span>
          <span class="ic-info-val ic-blood"><?= $blood ?></span>
        </div>
        <div class="ic-info-row">
          <span class="ic-info-ico"><i class="fa fa-user-o"></i></span>
          <span class="ic-info-lbl">Father</span>
          <span class="ic-info-val"><?= $father ?></span>
        </div>
        <div class="ic-info-row">
          <span class="ic-info-ico"><i class="fa fa-phone"></i></span>
          <span class="ic-info-lbl">Emrg</span>
          <span class="ic-info-val ic-phone"><?= $emergency ?></span>
        </div>
      </div>

      <!-- Footer band -->
      <div class="ic-card-ft">
        <span class="ic-ft-title">STUDENT ID CARD</span>
        <span class="ic-ft-valid">Valid: <?= htmlspecialchars($session_year) ?></span>
      </div>

    </div><!-- /.ic-card -->

    <!-- Print action below card -->
    <button class="ic-print-btn"
            data-print="<?= htmlspecialchars(json_encode([$safeUid, $name, $school_name, $session_year]), ENT_QUOTES, 'UTF-8') ?>"
            onclick="var d=JSON.parse(this.dataset.print);icPrintOne(d[0],d[1],d[2],d[3])">
      <i class="fa fa-print"></i> Print
    </button>

  </div><!-- /.ic-card-wrap -->
  <?php endforeach; ?>
  </div><!-- /#icGrid -->

  <!-- No-result message (shown by JS) -->
  <div id="icNoResult" class="ic-empty" style="display:none">
    <i class="fa fa-search"></i>
    <p>No students match your filters.</p>
  </div>

  <?php endif; ?>

</div><!-- /.ic-wrap -->
</div><!-- /.content-wrapper -->


<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<script>
/* ══════════════════════════════════════════════════════════════════
   Student ID Card — Filter & Print Logic
══════════════════════════════════════════════════════════════════ */
(function () {
  'use strict';

  var grid      = document.getElementById('icGrid');
  var noResult  = document.getElementById('icNoResult');
  var countEl   = document.getElementById('icCount');
  var searchIn  = document.getElementById('icSearch');
  var classSel  = document.getElementById('icClass');
  var sectSel   = document.getElementById('icSection');

  function applyFilters() {
    if (!grid) return;
    var q    = (searchIn.value || '').toLowerCase().trim();
    var cls  = classSel.value;
    var sect = sectSel.value;
    var visible = 0;

    Array.prototype.forEach.call(grid.children, function (wrap) {
      var matchQ = !q || (
        (wrap.dataset.name   || '').indexOf(q) > -1 ||
        (wrap.dataset.uid    || '').indexOf(q) > -1 ||
        (wrap.dataset.father || '').indexOf(q) > -1
      );
      var matchC = !cls  || wrap.dataset.class   === cls;
      var matchS = !sect || wrap.dataset.section === sect;

      var show = matchQ && matchC && matchS;
      wrap.style.display = show ? '' : 'none';
      if (show) visible++;
    });

    countEl.textContent = visible;
    if (noResult) noResult.style.display = (visible === 0 && grid.children.length > 0) ? 'block' : 'none';
  }

  if (searchIn) searchIn.addEventListener('input',  applyFilters);
  if (classSel) classSel.addEventListener('change', applyFilters);
  if (sectSel)  sectSel.addEventListener('change',  applyFilters);

  /* ── Print one card ──────────────────────────────────────────── */
  window.icPrintOne = function (safeUid, name, school, session) {
    var cardEl = document.getElementById('card-' + safeUid);
    if (!cardEl) return;

    var w = window.open('', '_blank', 'width=480,height=700');
    w.document.write(
      '<!DOCTYPE html><html><head><meta charset="UTF-8">' +
      '<title>ID Card — ' + name + '</title>' +
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
      '<title>Student ID Cards</title>' +
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
          width: 1,
          height: 24,
          displayValue: false,
          margin: 0,
          background: 'transparent'
        });
      } catch(e) { /* silently skip invalid codes */ }
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() { initBarcodes(); });
  } else {
    initBarcodes();
  }

  /* ── Card CSS (shared between screen hover-print and print window) */
  function getCardCSS(forPrint) {
    return [
      /* Card shell */
      '.ic-card{width:200px;border-radius:12px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.18);font-family:Arial,sans-serif;background:#fff;display:flex;flex-direction:column;align-items:center;}',
      /* Header */
      '.ic-card-hd{width:100%;background:linear-gradient(135deg,#0f766e 0%,#134e4a 100%);padding:10px 12px;position:relative;overflow:hidden;box-sizing:border-box;}',
      '.ic-hd-pattern{position:absolute;inset:0;background:repeating-linear-gradient(45deg,rgba(255,255,255,.04) 0,rgba(255,255,255,.04) 1px,transparent 1px,transparent 8px);}',
      '.ic-hd-content{position:relative;display:flex;align-items:center;gap:8px;}',
      '.ic-logo-circle{width:30px;height:30px;border-radius:50%;background:rgba(255,255,255,.18);border:1.5px solid rgba(255,255,255,.5);display:flex;align-items:center;justify-content:center;color:#fff;font-size:.62rem;font-weight:700;letter-spacing:.05em;flex-shrink:0;}',
      '.ic-school-nm{color:#fff;font-size:.72rem;font-weight:700;letter-spacing:.04em;line-height:1.2;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:130px;}',
      '.ic-session-nm{color:rgba(255,255,255,.75);font-size:.6rem;margin-top:1px;}',
      /* Photo ring */
      '.ic-photo-ring{width:74px;height:74px;border-radius:50%;border:3px solid #0f766e;box-shadow:0 2px 12px rgba(15,118,110,.3);margin-top:12px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:#e6f4f1;flex-shrink:0;}',
      '.ic-photo{width:100%;height:100%;object-fit:cover;}',
      '.ic-photo-fb{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:700;color:#0f766e;background:#e6f4f1;}',
      /* Name & barcode */
      '.ic-student-nm{font-size:.82rem;font-weight:700;color:#111;text-align:center;margin:8px 10px 4px;line-height:1.3;}',
      '.ic-barcode-wrap{width:85%;text-align:center;margin:2px auto 0;padding:0 4px;}',
      '.ic-barcode{width:100%;height:24px;}',
      '.ic-badges{display:flex;flex-wrap:wrap;justify-content:center;gap:4px;margin-bottom:6px;padding:0 8px;}',
      '.ic-badge{font-size:.58rem;font-weight:700;padding:2px 7px;border-radius:20px;letter-spacing:.04em;text-transform:uppercase;}',
      '.ic-badge-cls{background:#ccfbf1;color:#0f766e;}',
      '.ic-badge-sec{background:#d1fae5;color:#065f46;}',
      '.ic-badge-gen{background:#e0f2fe;color:#0369a1;}',
      /* Divider */
      '.ic-divider{width:85%;height:1px;background:linear-gradient(90deg,transparent,#0f766e55,transparent);margin:2px 0 6px;}',
      /* Info rows */
      '.ic-info{width:100%;padding:0 14px 8px;box-sizing:border-box;}',
      '.ic-info-row{display:flex;align-items:center;gap:5px;padding:3px 0;border-bottom:1px solid #f0f9f8;}',
      '.ic-info-row:last-child{border-bottom:none;}',
      '.ic-info-ico{color:#0f766e;font-size:.7rem;width:14px;text-align:center;flex-shrink:0;}',
      '.ic-info-lbl{font-size:.62rem;color:#6b7280;font-weight:600;width:36px;flex-shrink:0;text-transform:uppercase;letter-spacing:.03em;}',
      '.ic-info-val{font-size:.68rem;color:#1f2937;font-weight:500;flex:1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}',
      '.ic-uid{color:#0f766e;font-weight:700;}',
      '.ic-blood{color:#dc2626;font-weight:700;}',
      '.ic-phone{color:#0369a1;font-weight:600;}',
      /* Footer band */
      '.ic-card-ft{width:100%;background:linear-gradient(135deg,#0f766e,#134e4a);padding:6px 12px;display:flex;justify-content:space-between;align-items:center;margin-top:auto;box-sizing:border-box;}',
      '.ic-ft-title{color:#fff;font-size:.58rem;font-weight:700;letter-spacing:.1em;}',
      '.ic-ft-valid{color:rgba(255,255,255,.75);font-size:.55rem;}',
      forPrint ? '.ic-print-body{margin:0;padding:0;background:#fff;}' : ''
    ].join('');
  }

})();
</script>


<style>
/* ═══════════════════════════════════════════════════════════════
   Student ID Card Page — teal / navy global theme
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
  font-size: 1.45rem;
  font-weight: 700;
  color: var(--t1);
  display: flex;
  align-items: center;
  gap: 10px;
  margin: 0 0 4px;
}
.ic-page-title i { color: var(--gold); }

.ic-breadcrumb {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  gap: 6px;
  font-size: .82rem;
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
  gap: 7px;
  padding: 9px 20px;
  border-radius: 8px;
  font-size: .88rem;
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
  gap: 10px;
  background: var(--bg2);
  border: 1px solid var(--border);
  border-radius: 10px;
  padding: 12px 16px;
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
  left: 11px;
  top: 50%;
  transform: translateY(-50%);
  color: var(--t3);
  font-size: .85rem;
  pointer-events: none;
}
.ic-search-input {
  width: 100%;
  padding: 8px 12px 8px 32px;
  border: 1px solid var(--border);
  border-radius: 7px;
  background: var(--bg3);
  color: var(--t1);
  font-size: .88rem;
  box-sizing: border-box;
  transition: border-color .18s, box-shadow .18s;
}
.ic-search-input:focus {
  outline: none;
  border-color: var(--gold);
  box-shadow: 0 0 0 3px var(--gold-ring);
}

.ic-select {
  padding: 8px 12px;
  border: 1px solid var(--border);
  border-radius: 7px;
  background: var(--bg3);
  color: var(--t1);
  font-size: .88rem;
  cursor: pointer;
  min-width: 140px;
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
  grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
  gap: 28px;
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
  width: 200px;
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
  padding: 10px 12px;
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
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: rgba(255,255,255,.18);
  border: 1.5px solid rgba(255,255,255,.5);
  display: flex;
  align-items: center;
  justify-content: center;
  color: #fff;
  font-size: .62rem;
  font-weight: 700;
  letter-spacing: .05em;
  flex-shrink: 0;
}
.ic-school-nm {
  color: #fff;
  font-size: .72rem;
  font-weight: 700;
  letter-spacing: .04em;
  line-height: 1.25;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 130px;
}
.ic-session-nm {
  color: rgba(255,255,255,.75);
  font-size: .6rem;
  margin-top: 1px;
}

/* Photo ring */
.ic-photo-ring {
  width: 76px;
  height: 76px;
  border-radius: 50%;
  border: 3px solid var(--gold);
  box-shadow: 0 2px 14px rgba(15,118,110,.28);
  margin-top: -14px;         /* overlaps the header band */
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
.ic-student-nm {
  font-size: .82rem;
  font-weight: 700;
  color: var(--t1);
  text-align: center;
  margin: 9px 10px 4px;
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
  height: 24px;
}
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
  font-size: .58rem;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 20px;
  letter-spacing: .04em;
  text-transform: uppercase;
}
.ic-badge-cls { background: var(--gold-dim); color: var(--gold); }
.ic-badge-sec { background: rgba(5,150,105,.12); color: #059669; }
.ic-badge-gen { background: rgba(59,130,246,.1); color: #2563eb; }

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
  font-size: .7rem;
  width: 14px;
  text-align: center;
  flex-shrink: 0;
}
.ic-info-lbl {
  font-size: .6rem;
  color: var(--t3);
  font-weight: 600;
  width: 36px;
  flex-shrink: 0;
  text-transform: uppercase;
  letter-spacing: .03em;
}
.ic-info-val {
  font-size: .68rem;
  color: var(--t1);
  font-weight: 500;
  flex: 1;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.ic-uid   { color: var(--gold);  font-weight: 700; }
.ic-blood { color: #dc2626; font-weight: 700; }
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
  font-size: .58rem;
  font-weight: 700;
  letter-spacing: .1em;
}
.ic-ft-valid {
  color: rgba(255,255,255,.75);
  font-size: .55rem;
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
  .ic-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 16px; }
  .ic-card { width: 100%; max-width: 200px; }
  .ic-topbar { flex-direction: column; }
}
@media (max-width: 380px) {
  .ic-grid { grid-template-columns: 1fr; }
}
</style>
