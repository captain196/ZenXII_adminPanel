<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<div class="eml-wrap">

  <!-- ── Page Header ──────────────────────────────────────────────── -->
  <div class="eml-header">
    <div>
      <div class="eml-page-title"><i class="fa fa-trophy"></i> Merit List</div>
      <ol class="eml-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('examination') ?>">Examination</a></li>
        <li>Merit List</li>
      </ol>
    </div>
    <div class="eml-header-actions" id="emlHeaderActions" style="display:none;">
      <button class="eml-btn-outline" onclick="printMeritList()">
        <i class="fa fa-print"></i> Print
      </button>
      <button class="eml-btn-outline" onclick="exportMeritList()">
        <i class="fa fa-download"></i> Export
      </button>
    </div>
  </div>

  <!-- ── Filter Bar ──────────────────────────────────────────────── -->
  <div class="eml-filter-card">
    <div class="eml-filter-row">
      <div class="eml-form-group">
        <label class="eml-label">Exam</label>
        <select id="emlExamSel" class="eml-select">
          <option value="">-- Select Exam --</option>
          <?php foreach ($exams as $ex): ?>
          <option value="<?= htmlspecialchars($ex['id']) ?>">
            <?= htmlspecialchars($ex['Name'] ?? $ex['id']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="eml-form-group">
        <label class="eml-label">Class</label>
        <select id="emlClassSel" class="eml-select">
          <option value="">-- Select Class --</option>
          <?php foreach ($structure as $ck => $sections): ?>
          <option value="<?= htmlspecialchars($ck) ?>"><?= htmlspecialchars($ck) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="eml-form-group">
        <label class="eml-label">Section</label>
        <select id="emlSectionSel" class="eml-select">
          <option value="">All Sections</option>
        </select>
      </div>
      <div class="eml-form-group">
        <label class="eml-label">Top N</label>
        <select id="emlTopN" class="eml-select">
          <option value="5">Top 5</option>
          <option value="10" selected>Top 10</option>
          <option value="15">Top 15</option>
          <option value="20">Top 20</option>
          <option value="25">Top 25</option>
          <option value="50">Top 50</option>
        </select>
      </div>
      <div class="eml-form-group eml-form-group--btn">
        <button id="emlGenerateBtn" class="eml-btn-primary" onclick="generateMeritList()">
          <i class="fa fa-bolt"></i> Generate
        </button>
      </div>
    </div>
  </div>

  <!-- ── Loading Spinner ─────────────────────────────────────────── -->
  <div id="emlLoading" class="eml-loading" style="display:none;">
    <i class="fa fa-spinner fa-spin fa-2x"></i>
    <p>Generating merit list&hellip;</p>
  </div>

  <!-- ── Empty State ─────────────────────────────────────────────── -->
  <div id="emlEmpty" class="eml-empty" style="display:none;">
    <i class="fa fa-inbox fa-3x"></i>
    <p>Select an exam and class, then click <strong>Generate</strong>.</p>
  </div>

  <!-- ── Results Area ────────────────────────────────────────────── -->
  <div id="emlResults" style="display:none;">

    <!-- Summary Banner -->
    <div class="eml-summary" id="emlSummary"></div>

    <!-- Overall Toppers -->
    <div class="eml-section">
      <div class="eml-section-title">
        <i class="fa fa-star"></i> Overall Toppers
      </div>
      <div class="eml-table-outer">
        <table class="eml-table" id="emlToppersTable">
          <thead>
            <tr>
              <th class="eml-th-rank">Rank</th>
              <th>Name</th>
              <th>Section</th>
              <th class="eml-th-num">Marks</th>
              <th class="eml-th-num">Percentage</th>
              <th>Grade</th>
              <th>Result</th>
            </tr>
          </thead>
          <tbody id="emlToppersBody"></tbody>
        </table>
      </div>
    </div>

    <!-- Subject-wise Toppers -->
    <div class="eml-section">
      <div class="eml-section-title">
        <i class="fa fa-book"></i> Subject-wise Toppers
      </div>
      <div id="emlSubjectToppers"></div>
    </div>

  </div>

</div>
</div>

<!-- ══════════════════════════════════════════════════════════════════
     STYLES
     ══════════════════════════════════════════════════════════════════ -->
<style>
/* ── Layout ───────────────────────────────────────────────────────── */
.eml-wrap{padding:24px 28px 48px;margin:0}
.eml-header{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.eml-page-title{font-family:var(--font-b);font-size:1.55rem;font-weight:700;color:var(--t1)}
.eml-page-title i{color:var(--gold);margin-right:8px;font-size:1.3rem}
.eml-breadcrumb{list-style:none;display:flex;gap:6px;padding:0;margin:4px 0 0;font-size:13px;color:var(--t3)}
.eml-breadcrumb li+li::before{content:"/";margin-right:6px;color:var(--t3)}
.eml-breadcrumb a{color:var(--gold);text-decoration:none}
.eml-breadcrumb a:hover{text-decoration:underline}
.eml-header-actions{display:flex;gap:8px}

/* ── Buttons ──────────────────────────────────────────────────────── */
.eml-btn-primary{
  background:var(--gold);color:#fff;border:none;padding:10px 22px;border-radius:10px;
  font-size:14px;font-weight:600;cursor:pointer;transition:all var(--ease);
  display:inline-flex;align-items:center;gap:7px;
}
.eml-btn-primary:hover{background:var(--gold2);box-shadow:0 4px 14px var(--gold-glow)}
.eml-btn-outline{
  background:transparent;color:var(--gold);border:1.5px solid var(--gold);padding:9px 20px;
  border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;
  transition:all var(--ease);display:inline-flex;align-items:center;gap:7px;
}
.eml-btn-outline:hover{background:var(--gold-dim)}

/* ── Filter Card ──────────────────────────────────────────────────── */
.eml-filter-card{
  background:var(--bg2);border:1px solid var(--border);border-radius:14px;
  padding:20px 24px;margin-bottom:24px;
}
.eml-filter-row{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end}
.eml-form-group{display:flex;flex-direction:column;gap:4px;min-width:140px;flex:1}
.eml-form-group--btn{flex:0 0 auto;min-width:auto;justify-content:flex-end}
.eml-label{font-family:var(--font-m);font-size:12.5px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.4px}
.eml-select{
  background:var(--bg);color:var(--t1);border:1px solid var(--border);border-radius:10px;
  padding:10px 12px;font-size:14px;
  appearance:none;-webkit-appearance:none;
  background-image:url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6'%3E%3Cpath d='M0 0l5 6 5-6z' fill='%23888'/%3E%3C/svg%3E");
  background-repeat:no-repeat;background-position:right 10px center;
  padding-right:28px;cursor:pointer;transition:border-color .2s;
}
.eml-select:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-ring)}

/* ── Loading / Empty ──────────────────────────────────────────────── */
.eml-loading,.eml-empty{text-align:center;padding:48px 24px;color:var(--t3)}
.eml-loading i,.eml-empty i{color:var(--gold);margin-bottom:10px;display:block;font-size:2rem}
.eml-loading p,.eml-empty p{margin:8px 0 0;font-size:14px}

/* ── Summary Banner ───────────────────────────────────────────────── */
.eml-summary{
  background:linear-gradient(135deg, var(--gold), var(--gold2));color:#fff;
  border-radius:14px;padding:16px 22px;margin-bottom:22px;
  display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;
  font-size:13px;
}
.eml-summary-item{display:flex;align-items:center;gap:6px}
.eml-summary-item i{font-size:14px;opacity:.85}
.eml-summary-val{font-family:var(--font-b);font-size:16px}

/* ── Section Titles ───────────────────────────────────────────────── */
.eml-section{margin-bottom:22px}
.eml-section-title{
  font-family:var(--font-b);font-size:16px;font-weight:700;color:var(--t1);
  margin-bottom:14px;display:flex;align-items:center;gap:10px;
}
.eml-section-title i{color:var(--gold)}

/* ── Tables ───────────────────────────────────────────────────────── */
.eml-table-outer{overflow-x:auto;border-radius:14px;border:1px solid var(--border)}
.eml-table{width:100%;border-collapse:collapse;font-size:14px}
.eml-table th{
  background:var(--bg3);color:var(--t2);font-size:12px;font-weight:700;
  text-transform:uppercase;letter-spacing:.5px;padding:12px 16px;text-align:left;
  border-bottom:2px solid var(--border);white-space:nowrap;
}
.eml-th-rank{width:60px;text-align:center}
.eml-th-num{text-align:right;width:90px}
.eml-table td{padding:12px 16px;color:var(--t1);border-bottom:1px solid var(--border)}
.eml-table tbody tr:nth-child(odd){background:rgba(15,118,110,.03)}
.eml-table tbody tr:hover{background:var(--gold-dim)}
.eml-table tbody tr:last-child td{border-bottom:none}

/* ── Rank Badges ──────────────────────────────────────────────────── */
.eml-rank{
  display:inline-flex;align-items:center;justify-content:center;
  width:28px;height:28px;border-radius:50%;font-family:var(--font-b);font-size:12px;
  color:#fff;
}
.eml-rank--1{background:linear-gradient(135deg,#f59e0b,#d97706)}
.eml-rank--2{background:linear-gradient(135deg,#9ca3af,#6b7280)}
.eml-rank--3{background:linear-gradient(135deg,#d97706,#b45309)}
.eml-rank--other{background:var(--bg3);color:var(--t2);border:1px solid var(--border)}

.eml-rank-cell{text-align:center}

/* ── Pass / Fail Badges ───────────────────────────────────────────── */
.eml-pass{color:#16a34a;font-family:var(--font-m);font-weight:700;font-size:12px}
.eml-fail{color:#dc2626;font-family:var(--font-m);font-weight:700;font-size:12px}

/* ── Subject Accordion ────────────────────────────────────────────── */
.eml-subj-block{margin-bottom:8px;border:1px solid var(--border);border-radius:var(--r-sm);overflow:hidden}
.eml-subj-header{
  background:var(--bg3);padding:12px 18px;cursor:pointer;
  display:flex;align-items:center;justify-content:space-between;
  font-size:14px;font-weight:600;color:var(--t1);
  transition:background .2s;user-select:none;
}
.eml-subj-header:hover{background:var(--gold-dim)}
.eml-subj-header i.eml-chevron{transition:transform .25s;color:var(--t3);font-size:13px}
.eml-subj-header.eml-open i.eml-chevron{transform:rotate(180deg)}
.eml-subj-body{display:none;padding:0}
.eml-subj-body.eml-open{display:block}
.eml-subj-table{width:100%;border-collapse:collapse;font-size:14px}
.eml-subj-table th{
  background:var(--bg2);color:var(--t3);font-size:12px;font-weight:700;text-transform:uppercase;
  letter-spacing:.5px;padding:10px 16px;text-align:left;border-bottom:1px solid var(--border);
}
.eml-subj-table td{padding:10px 16px;color:var(--t1);border-bottom:1px solid var(--border)}
.eml-subj-table tbody tr:nth-child(odd){background:rgba(15,118,110,.03)}
.eml-subj-name{display:flex;align-items:center;gap:6px}
.eml-subj-name i{color:var(--gold);font-size:12px}

/* ── Responsive ───────────────────────────────────────────────────── */
@media(max-width:767px){
  .eml-wrap{padding:14px 10px 36px}
  .eml-filter-row{flex-direction:column}
  .eml-form-group{min-width:100%}
  .eml-header{flex-direction:column}
  .eml-header-actions{width:100%;justify-content:flex-end}
  .eml-summary{flex-direction:column;text-align:center}
  .eml-table th,.eml-table td{padding:7px 8px}
}

/* ── Print ────────────────────────────────────────────────────────── */
@media print{
  body *{visibility:hidden}
  .eml-wrap,.eml-wrap *{visibility:visible}
  .eml-wrap{position:absolute;left:0;top:0;width:100%;padding:0;max-width:none}
  .eml-filter-card,.eml-header-actions,.eml-loading,.eml-empty,
  .content-wrapper>.main-sidebar,.main-header,.main-footer{display:none!important}
  .eml-summary{background:var(--gold)!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .eml-rank{-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .eml-table-outer{border:1px solid #ccc;box-shadow:none}
  .eml-table tbody tr:nth-child(odd){background:#f9fafb!important;-webkit-print-color-adjust:exact;print-color-adjust:exact}
  .eml-subj-body{display:block!important}
  @page{margin:12mm}
}
</style>

<!-- ══════════════════════════════════════════════════════════════════
     JAVASCRIPT
     ══════════════════════════════════════════════════════════════════ -->
<script>
document.addEventListener('DOMContentLoaded', function(){
(function(){
  'use strict';

  // ── Class/Section structure from PHP ────────────────────────────
  var STRUCTURE = <?= json_encode($structure, JSON_UNESCAPED_UNICODE) ?>;

  // ── XSS helper ─────────────────────────────────────────────────
  var _escEl = document.createElement('div');
  function esc(str) {
    if (str === null || str === undefined) return '';
    _escEl.textContent = String(str);
    return _escEl.innerHTML;
  }

  // ── CSRF helpers ───────────────────────────────────────────────
  function csrfName() { return $('meta[name="csrf-name"]').attr('content'); }
  function csrfToken() { return $('meta[name="csrf-token"]').attr('content'); }
  function refreshCsrf(r) {
    if (r && r.csrf_token) {
      $('meta[name="csrf-token"]').attr('content', r.csrf_token);
    }
  }

  // ── Section dropdown cascade ───────────────────────────────────
  $('#emlClassSel').on('change', function(){
    var cls = $(this).val();
    var $sec = $('#emlSectionSel');
    $sec.html('<option value="">All Sections</option>');
    if (cls && STRUCTURE[cls]) {
      var sections = STRUCTURE[cls];
      for (var i = 0; i < sections.length; i++) {
        $sec.append('<option value="' + esc(sections[i]) + '">Section ' + esc(sections[i]) + '</option>');
      }
    }
  });

  // ── Generate Merit List ────────────────────────────────────────
  window.generateMeritList = function() {
    var examId  = $('#emlExamSel').val();
    var classKey = $('#emlClassSel').val();
    var section  = $('#emlSectionSel').val();
    var topN     = $('#emlTopN').val();

    if (!examId) { alert('Please select an exam.'); return; }
    if (!classKey) { alert('Please select a class.'); return; }

    var payload = { examId: examId, classKey: classKey, topN: topN };
    payload[csrfName()] = csrfToken();
    if (section) payload.sectionKey = 'Section ' + section;

    $('#emlResults, #emlEmpty, #emlHeaderActions').hide();
    $('#emlLoading').show();

    $.ajax({
      url: BASE_URL + 'examination/get_merit_data',
      type: 'POST',
      data: payload,
      dataType: 'json',
      success: function(r) {
        refreshCsrf(r);
        $('#emlLoading').hide();

        if (!r || r.status !== 'success') {
          alert(r && r.message ? r.message : 'Failed to load merit data.');
          $('#emlEmpty').show();
          return;
        }

        renderSummary(r);
        renderToppers(r.toppers || []);
        renderSubjectToppers(r.subjectToppers || {});
        $('#emlResults, #emlHeaderActions').show();
      },
      error: function() {
        $('#emlLoading').hide();
        $('#emlEmpty').show();
        alert('Network error. Please try again.');
      }
    });
  };

  // ── Render Summary Banner ──────────────────────────────────────
  function renderSummary(r) {
    var examName = esc($('#emlExamSel option:selected').text());
    var className = esc($('#emlClassSel').val());
    var sectionTxt = $('#emlSectionSel').val() ? ' - Section ' + esc($('#emlSectionSel').val()) : ' (All Sections)';
    var total = r.totalStudents || 0;
    var passed = (r.toppers || []).filter(function(t){ return t.passFail === 'Pass'; }).length;

    var html = '';
    html += '<div class="eml-summary-item"><i class="fa fa-file-text-o"></i> ' + examName + '</div>';
    html += '<div class="eml-summary-item"><i class="fa fa-graduation-cap"></i> ' + className + sectionTxt + '</div>';
    html += '<div class="eml-summary-item"><i class="fa fa-users"></i> Total: <span class="eml-summary-val">' + total + '</span></div>';
    html += '<div class="eml-summary-item"><i class="fa fa-check-circle"></i> Passed: <span class="eml-summary-val">' + passed + '</span></div>';
    $('#emlSummary').html(html);
  }

  // ── Render Overall Toppers ─────────────────────────────────────
  function renderToppers(toppers) {
    var $body = $('#emlToppersBody');
    $body.empty();

    if (!toppers.length) {
      $body.html('<tr><td colspan="7" style="text-align:center;color:var(--t3);padding:30px;">No topper data available.</td></tr>');
      return;
    }

    for (var i = 0; i < toppers.length; i++) {
      var t = toppers[i];
      var rank = parseInt(t.rank) || (i + 1);
      var rankBadge = buildRankBadge(rank);
      var resultClass = (t.passFail && t.passFail.toLowerCase() === 'pass') ? 'eml-pass' : 'eml-fail';
      var resultLabel = (t.passFail && t.passFail.toLowerCase() === 'pass') ? 'Pass' : 'Fail';

      var tr = '<tr>';
      tr += '<td class="eml-rank-cell">' + rankBadge + '</td>';
      tr += '<td>' + esc(t.name) + '</td>';
      tr += '<td>' + esc(t.section || '-') + '</td>';
      tr += '<td style="text-align:right;font-family:var(--font-m)">' + esc(t.totalMarks) + '</td>';
      tr += '<td style="text-align:right;font-family:var(--font-m)">' + esc(t.percentage) + '%</td>';
      tr += '<td>' + esc(t.grade || '-') + '</td>';
      tr += '<td><span class="' + resultClass + '">' + resultLabel + '</span></td>';
      tr += '</tr>';
      $body.append(tr);
    }
  }

  // ── Rank Badge Builder ─────────────────────────────────────────
  function buildRankBadge(rank) {
    var icon = '';
    var cls  = 'eml-rank eml-rank--other';

    if (rank === 1) {
      icon = '<i class="fa fa-trophy eml-rank-icon" style="color:#f59e0b"></i> ';
      cls = 'eml-rank eml-rank--1';
    } else if (rank === 2) {
      icon = '<i class="fa fa-trophy eml-rank-icon" style="color:#9ca3af"></i> ';
      cls = 'eml-rank eml-rank--2';
    } else if (rank === 3) {
      icon = '<i class="fa fa-trophy eml-rank-icon" style="color:#d97706"></i> ';
      cls = 'eml-rank eml-rank--3';
    }

    return '<span class="' + cls + '">' + rank + '</span>';
  }

  // ── Render Subject-wise Toppers ────────────────────────────────
  function renderSubjectToppers(subjectToppers) {
    var $wrap = $('#emlSubjectToppers');
    $wrap.empty();

    var subjects = Object.keys(subjectToppers);
    if (!subjects.length) {
      $wrap.html('<p style="color:var(--t3);text-align:center;padding:20px;">No subject-wise data available.</p>');
      return;
    }

    for (var s = 0; s < subjects.length; s++) {
      var subj = subjects[s];
      var students = subjectToppers[subj];

      var html = '<div class="eml-subj-block">';
      html += '<div class="eml-subj-header" onclick="toggleSubject(this)">';
      html += '<span class="eml-subj-name"><i class="fa fa-book"></i> ' + esc(subj) + ' <small style="color:var(--t3)">(' + students.length + ' student' + (students.length !== 1 ? 's' : '') + ')</small></span>';
      html += '<i class="fa fa-chevron-down eml-chevron"></i>';
      html += '</div>';
      html += '<div class="eml-subj-body">';
      html += '<table class="eml-subj-table"><thead><tr>';
      html += '<th style="width:60px;text-align:center">Rank</th>';
      html += '<th>Name</th>';
      html += '<th>Section</th>';
      html += '<th style="text-align:right;width:90px">Marks</th>';
      html += '<th style="text-align:right;width:100px">Percentage</th>';
      html += '</tr></thead><tbody>';

      for (var j = 0; j < students.length; j++) {
        var st = students[j];
        var rk = parseInt(st.rank) || (j + 1);
        html += '<tr>';
        html += '<td style="text-align:center">' + buildRankBadge(rk) + '</td>';
        html += '<td>' + esc(st.name) + '</td>';
        html += '<td>' + esc(st.section || '-') + '</td>';
        html += '<td style="text-align:right;font-family:var(--font-m)">' + esc(st.total) + '</td>';
        html += '<td style="text-align:right;font-family:var(--font-m)">' + esc(st.percentage) + '%</td>';
        html += '</tr>';
      }

      html += '</tbody></table></div></div>';
      $wrap.append(html);
    }
  }

  // ── Subject Accordion Toggle ───────────────────────────────────
  window.toggleSubject = function(el) {
    var $hdr  = $(el);
    var $body = $hdr.next('.eml-subj-body');
    $hdr.toggleClass('eml-open');
    $body.toggleClass('eml-open');
    if ($body.hasClass('eml-open')) {
      $body.slideDown(200);
    } else {
      $body.slideUp(200);
    }
  };

  // ── Print ──────────────────────────────────────────────────────
  window.printMeritList = function() {
    // Expand all subject accordions before printing
    $('.eml-subj-body').addClass('eml-open').show();
    $('.eml-subj-header').addClass('eml-open');
    window.print();
  };

  // ── Export to CSV ──────────────────────────────────────────────
  window.exportMeritList = function() {
    var rows = [];
    rows.push(['Rank','Name','Section','Marks','Percentage','Grade','Result']);

    $('#emlToppersBody tr').each(function(){
      var cells = [];
      $(this).find('td').each(function(idx){
        var text = $(this).text().trim();
        cells.push('"' + text.replace(/"/g, '""') + '"');
      });
      if (cells.length > 1) rows.push(cells);
    });

    var examName = $('#emlExamSel option:selected').text().trim();
    var className = $('#emlClassSel').val() || '';
    var sectionVal = $('#emlSectionSel').val();
    var fileName = 'Merit_List_' + examName.replace(/[^a-zA-Z0-9]/g, '_') + '_' + className.replace(/[^a-zA-Z0-9]/g, '_');
    if (sectionVal) fileName += '_Section_' + sectionVal;
    fileName += '.csv';

    var csvContent = rows.map(function(r){ return r.join(','); }).join('\n');
    var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = fileName;
    link.style.display = 'none';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  };

})();
});
</script>
