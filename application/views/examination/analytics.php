<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<div class="content-wrapper">
<section class="content">
<div class="epa-wrap">

  <!-- ── Page Header ───────────────────────────────────────────────── -->
  <div class="epa-header">
    <div class="epa-page-title"><i class="fa fa-line-chart"></i> Performance Analytics</div>
    <ol class="epa-breadcrumb">
      <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
      <li><a href="<?= base_url('examination') ?>">Examinations</a></li>
      <li>Analytics</li>
    </ol>
  </div>

  <!-- ── Filter Bar ────────────────────────────────────────────────── -->
  <div class="epa-filter-bar">
    <div class="epa-filter-group">
      <label class="epa-label" for="epaExam"><i class="fa fa-file-text-o"></i> Exam</label>
      <select id="epaExam" class="epa-select">
        <option value="">-- Select Exam --</option>
        <?php foreach ($exams as $ex): ?>
          <option value="<?= htmlspecialchars($ex['id']) ?>"
                  data-type="<?= htmlspecialchars($ex['Type'] ?? '') ?>"
                  data-grading="<?= htmlspecialchars($ex['GradingScale'] ?? '') ?>"
                  data-passing="<?= htmlspecialchars($ex['PassingPercent'] ?? '') ?>">
            <?= htmlspecialchars($ex['Name'] ?? $ex['id']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="epa-filter-group">
      <label class="epa-label" for="epaClass"><i class="fa fa-graduation-cap"></i> Class</label>
      <select id="epaClass" class="epa-select">
        <option value="">-- Select Class --</option>
        <?php foreach ($structure as $classKey => $sections): ?>
          <option value="<?= htmlspecialchars($classKey) ?>"><?= htmlspecialchars($classKey) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="epa-filter-group">
      <label class="epa-label" for="epaSection"><i class="fa fa-th-large"></i> Section</label>
      <select id="epaSection" class="epa-select">
        <option value="">All Sections</option>
      </select>
    </div>
    <div class="epa-filter-group epa-filter-action">
      <button id="epaAnalyzeBtn" class="epa-btn epa-btn-primary" onclick="analyzeExam()">
        <i class="fa fa-bar-chart"></i> Analyze
      </button>
    </div>
  </div>

  <!-- ── Loading Spinner ───────────────────────────────────────────── -->
  <div id="epaSpinner" class="epa-spinner" style="display:none;">
    <i class="fa fa-circle-o-notch fa-spin fa-2x"></i>
    <span>Analyzing results&hellip;</span>
  </div>

  <!-- ── Analytics Dashboard ───────────────────────────────────────── -->
  <div id="epaDashboard" style="display:none;">

    <!-- Summary Cards -->
    <div id="epaSummaryCards" class="epa-cards-row"></div>

    <!-- Grade Distribution -->
    <div class="epa-panel" id="epaGradePanel" style="display:none;">
      <div class="epa-panel-title"><i class="fa fa-bar-chart"></i> Grade Distribution</div>
      <div id="epaGradeChart" class="epa-grade-chart"></div>
    </div>

    <!-- Subject Analysis -->
    <div class="epa-panel" id="epaSubjectPanel" style="display:none;">
      <div class="epa-panel-title"><i class="fa fa-book"></i> Subject Analysis</div>
      <div class="epa-table-wrap">
        <table class="epa-table" id="epaSubjectTable">
          <thead><tr></tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>

    <!-- Section Comparison -->
    <div class="epa-panel" id="epaSectionPanel" style="display:none;">
      <div class="epa-panel-title"><i class="fa fa-columns"></i> Section Comparison</div>
      <div id="epaSectionChart" class="epa-section-chart"></div>
    </div>
  </div>

  <!-- ── Exam Comparison ───────────────────────────────────────────── -->
  <div class="epa-panel epa-comparison-panel">
    <div class="epa-panel-title"><i class="fa fa-exchange"></i> Exam Comparison</div>
    <div class="epa-compare-bar">
      <div class="epa-filter-group">
        <label class="epa-label" for="epaExam1"><i class="fa fa-file-text-o"></i> Exam 1</label>
        <select id="epaExam1" class="epa-select">
          <option value="">-- Select Exam --</option>
          <?php foreach ($exams as $ex): ?>
            <option value="<?= htmlspecialchars($ex['id']) ?>"><?= htmlspecialchars($ex['Name'] ?? $ex['id']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="epa-filter-group">
        <label class="epa-label" for="epaExam2"><i class="fa fa-file-text-o"></i> Exam 2</label>
        <select id="epaExam2" class="epa-select">
          <option value="">-- Select Exam --</option>
          <?php foreach ($exams as $ex): ?>
            <option value="<?= htmlspecialchars($ex['id']) ?>"><?= htmlspecialchars($ex['Name'] ?? $ex['id']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="epa-filter-group">
        <label class="epa-label" for="epaCmpClass"><i class="fa fa-graduation-cap"></i> Class</label>
        <select id="epaCmpClass" class="epa-select">
          <option value="">-- Select Class --</option>
          <?php foreach ($structure as $classKey => $sections): ?>
            <option value="<?= htmlspecialchars($classKey) ?>"><?= htmlspecialchars($classKey) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="epa-filter-group">
        <label class="epa-label" for="epaCmpSection"><i class="fa fa-th-large"></i> Section</label>
        <select id="epaCmpSection" class="epa-select">
          <option value="">All Sections</option>
        </select>
      </div>
      <div class="epa-filter-group epa-filter-action">
        <button id="epaCompareBtn" class="epa-btn epa-btn-secondary" onclick="compareExams()">
          <i class="fa fa-exchange"></i> Compare
        </button>
      </div>
    </div>

    <div id="epaCmpSpinner" class="epa-spinner" style="display:none;">
      <i class="fa fa-circle-o-notch fa-spin fa-2x"></i>
      <span>Comparing exams&hellip;</span>
    </div>

    <div id="epaCmpResult" style="display:none;">
      <!-- Student Performance Comparison -->
      <div class="epa-sub-title"><i class="fa fa-users"></i> Student Performance Comparison</div>
      <div class="epa-table-wrap">
        <table class="epa-table" id="epaCmpStudentTable">
          <thead>
            <tr>
              <th>Name</th>
              <th>Exam 1 %</th>
              <th>Exam 2 %</th>
              <th>Change</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>

      <!-- Subject Average Comparison -->
      <div class="epa-sub-title" style="margin-top:24px;"><i class="fa fa-book"></i> Subject Average Comparison</div>
      <div class="epa-table-wrap">
        <table class="epa-table" id="epaCmpSubjectTable">
          <thead>
            <tr>
              <th>Subject</th>
              <th>Exam 1 Avg</th>
              <th>Exam 2 Avg</th>
              <th>Change</th>
            </tr>
          </thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.epa-wrap -->
</section>
</div><!-- /.content-wrapper -->

<!-- ──────────────────────────────────────────────────────────────────
     STYLES
     ──────────────────────────────────────────────────────────────── -->
<style>
/* ── Layout ─────────────────────────────────────────────────────── */
.epa-wrap{margin:0;padding:24px 28px 48px;}
.epa-header{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;margin-bottom:24px;}
.epa-page-title{font-family:var(--font-b);font-size:1.55rem;font-weight:700;color:var(--t1);}
.epa-page-title i{color:var(--gold);margin-right:8px;font-size:1.3rem;}
.epa-breadcrumb{list-style:none;display:flex;gap:6px;padding:0;margin:4px 0 0;font-size:13px;color:var(--t3);}
.epa-breadcrumb li+li::before{content:"/ ";color:var(--t3);}
.epa-breadcrumb a{color:var(--gold);text-decoration:none;}
.epa-breadcrumb a:hover{text-decoration:underline;}

/* ── Filter Bar ─────────────────────────────────────────────────── */
.epa-filter-bar,.epa-compare-bar{display:flex;flex-wrap:wrap;gap:14px;align-items:flex-end;
  background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:20px 24px;margin-bottom:24px;}
.epa-filter-group{display:flex;flex-direction:column;gap:5px;min-width:150px;flex:1 1 150px;}
.epa-filter-action{flex:0 0 auto;min-width:auto;justify-content:flex-end;}
.epa-label{font-size:12.5px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;}
.epa-label i{width:14px;text-align:center;margin-right:3px;}
.epa-select{width:100%;padding:10px 12px;border:1px solid var(--border);border-radius:10px;background:var(--bg);
  color:var(--t1);font-size:14px;outline:none;transition:border .2s;}
.epa-select:focus{border-color:var(--gold);}

/* ── Buttons ────────────────────────────────────────────────────── */
.epa-btn{display:inline-flex;align-items:center;gap:7px;padding:10px 22px;border:none;border-radius:10px;
  font-size:14px;font-weight:600;cursor:pointer;transition:background var(--ease),transform .1s;}
.epa-btn:active{transform:scale(.97);}
.epa-btn-primary{background:var(--gold);color:#fff;}
.epa-btn-primary:hover{background:var(--gold2);box-shadow:0 4px 14px var(--gold-glow);}
.epa-btn-secondary{background:var(--bg3);color:var(--t1);border:1px solid var(--border);}
.epa-btn-secondary:hover{background:var(--gold-dim);border-color:var(--gold);}

/* ── Spinner ────────────────────────────────────────────────────── */
.epa-spinner{display:flex;align-items:center;justify-content:center;gap:10px;padding:48px 24px;color:var(--gold);font-size:14px;}

/* ── Panel ──────────────────────────────────────────────────────── */
.epa-panel{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:22px;margin-bottom:24px;}
.epa-panel-title{font-family:var(--font-b);font-size:16px;font-weight:700;color:var(--t1);margin-bottom:16px;padding-bottom:12px;border-bottom:1px solid var(--border);}
.epa-panel-title i{color:var(--gold);margin-right:8px;}
.epa-sub-title{font-family:var(--font-b);font-size:15px;font-weight:700;color:var(--t1);margin-bottom:12px;}
.epa-sub-title i{color:var(--gold);margin-right:6px;}

/* ── Summary Cards ──────────────────────────────────────────────── */
.epa-cards-row{display:grid;grid-template-columns:repeat(4,1fr);gap:16px;margin-bottom:24px;}
.epa-card{background:var(--bg2);border:1px solid var(--border);border-radius:14px;padding:20px;text-align:center;
  position:relative;overflow:hidden;}
.epa-card::before{content:'';position:absolute;top:0;left:0;right:0;height:3px;background:var(--gold);}
.epa-card-icon{font-size:20px;color:var(--gold);margin-bottom:8px;}
.epa-card-value{font-family:var(--font-b);font-size:1.75rem;color:var(--t1);line-height:1.2;}
.epa-card-label{font-size:12px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;margin-top:5px;font-weight:600;}
.epa-card-sub{font-size:12.5px;color:var(--t2);margin-top:4px;}

/* Gauge (simple CSS arc) */
.epa-gauge{width:64px;height:32px;margin:5px auto 0;border-radius:64px 64px 0 0;background:var(--bg3);position:relative;overflow:hidden;}
.epa-gauge-fill{position:absolute;bottom:0;left:0;width:64px;height:32px;border-radius:64px 64px 0 0;
  transform-origin:center bottom;background:var(--gold);}
.epa-gauge-mask{position:absolute;bottom:0;left:3px;width:58px;height:29px;border-radius:58px 58px 0 0;background:var(--bg2);}

/* ── Grade Distribution Chart ───────────────────────────────────── */
.epa-grade-chart{display:flex;flex-direction:column;gap:8px;}
.epa-grade-row{display:flex;align-items:center;gap:8px;}
.epa-grade-label{width:30px;font-family:var(--font-b);font-size:12px;color:var(--t1);text-align:right;flex-shrink:0;}
.epa-grade-bar-wrap{flex:1;height:24px;background:var(--bg3);border-radius:5px;overflow:hidden;position:relative;}
.epa-grade-bar{height:100%;border-radius:5px;display:flex;align-items:center;padding-left:8px;
  transition:width .5s cubic-bezier(.22,1,.36,1);min-width:0;}
.epa-grade-bar span{font-size:10.5px;font-family:var(--font-b);color:#fff;white-space:nowrap;}
.epa-grade-count{width:36px;font-size:12px;color:var(--t2);text-align:left;flex-shrink:0;font-family:var(--font-m);}

/* Grade colors */
.epa-grade-aplus{background:#16a34a;}
.epa-grade-a{background:#22c55e;}
.epa-grade-bplus{background:#0f766e;}
.epa-grade-b{background:#14b8a6;}
.epa-grade-c{background:#d97706;}
.epa-grade-d{background:#ea580c;}
.epa-grade-f{background:#dc2626;}
.epa-grade-default{background:#64748b;}

/* ── Tables ─────────────────────────────────────────────────────── */
.epa-table-wrap{overflow-x:auto;}
.epa-table{width:100%;border-collapse:collapse;font-size:13px;}
.epa-table th{background:var(--bg3);color:var(--t2);font-family:var(--font-m);font-size:10.5px;font-weight:700;
  text-transform:uppercase;letter-spacing:.6px;padding:8px 10px;text-align:left;border-bottom:2px solid var(--border);
  cursor:pointer;user-select:none;white-space:nowrap;position:relative;}
.epa-table th:hover{color:var(--gold);}
.epa-table th .epa-sort-icon{margin-left:3px;font-size:9px;opacity:.5;}
.epa-table th.epa-sorted-asc .epa-sort-icon,
.epa-table th.epa-sorted-desc .epa-sort-icon{opacity:1;color:var(--gold);}
.epa-table td{padding:8px 10px;border-bottom:1px solid var(--border);color:var(--t1);}
.epa-table tbody tr:nth-child(odd){background:rgba(15,118,110,.03);}
.epa-table tbody tr:hover{background:var(--gold-dim);}
.epa-table tbody tr:last-child td{border-bottom:none;}

/* Pass rate badges */
.epa-rate{display:inline-block;padding:2px 9px;border-radius:20px;font-size:9.5px;font-weight:700;font-family:var(--font-m);}
.epa-rate-green{background:rgba(22,163,74,.12);color:#16a34a;}
.epa-rate-teal{background:rgba(15,118,110,.12);color:#0f766e;}
.epa-rate-amber{background:rgba(217,119,6,.12);color:#d97706;}
.epa-rate-red{background:rgba(220,38,38,.12);color:#dc2626;}

/* ── Section Comparison Chart ───────────────────────────────────── */
.epa-section-chart{display:flex;flex-direction:column;gap:10px;}
.epa-section-row{display:flex;align-items:center;gap:10px;}
.epa-section-label{width:70px;font-family:var(--font-b);font-size:13px;color:var(--t1);text-align:right;flex-shrink:0;}
.epa-section-bar-wrap{flex:1;height:26px;background:var(--bg3);border-radius:6px;overflow:hidden;position:relative;}
.epa-section-bar{height:100%;background:var(--gold);border-radius:6px;display:flex;align-items:center;
  justify-content:flex-end;padding-right:10px;transition:width .5s cubic-bezier(.22,1,.36,1);}
.epa-section-bar span{font-size:11px;font-family:var(--font-b);color:#fff;}
.epa-section-avg{width:46px;font-size:12px;color:var(--t2);font-family:var(--font-b);flex-shrink:0;}

/* ── Change indicators ──────────────────────────────────────────── */
.epa-change-up{color:#16a34a;font-family:var(--font-b);font-size:12px;}
.epa-change-down{color:#dc2626;font-family:var(--font-b);font-size:12px;}
.epa-change-flat{color:var(--t3);font-family:var(--font-m);font-size:12px;}

/* ── Comparison panel ───────────────────────────────────────────── */
.epa-comparison-panel{margin-top:8px;}

/* ── Responsive ─────────────────────────────────────────────────── */
@media(max-width:767px){
  .epa-cards-row{grid-template-columns:repeat(2,1fr);}
  .epa-filter-bar,.epa-compare-bar{flex-direction:column;gap:10px;}
  .epa-filter-group{min-width:100%;flex:1 1 100%;}
  .epa-section-label{width:50px;font-size:11px;}
  .epa-grade-label{width:24px;font-size:11px;}
  .epa-page-title{font-size:1.15rem;}
}

/* ── Print ──────────────────────────────────────────────────────── */
@media print{
  .epa-filter-bar,.epa-compare-bar,.epa-filter-action,.epa-comparison-panel,.epa-spinner,
  .content-header,.main-header,.main-sidebar,.main-footer{display:none!important;}
  .content-wrapper{margin-left:0!important;padding:0!important;}
  .epa-wrap{padding:0;}
  .epa-panel,.epa-card{break-inside:avoid;box-shadow:none;border:1px solid #ddd;}
  .epa-cards-row{grid-template-columns:repeat(4,1fr);}
  .epa-grade-bar{print-color-adjust:exact;-webkit-print-color-adjust:exact;}
  .epa-section-bar{print-color-adjust:exact;-webkit-print-color-adjust:exact;}
  .epa-rate{print-color-adjust:exact;-webkit-print-color-adjust:exact;}
}
</style>

<!-- ──────────────────────────────────────────────────────────────────
     JAVASCRIPT
     ──────────────────────────────────────────────────────────────── -->
<script>
document.addEventListener('DOMContentLoaded', function(){
(function(){
  'use strict';

  /* ── Class/Section structure from PHP ──────────────────────────── */
  var STRUCTURE = <?= json_encode($structure ? $structure : new stdClass()) ?>;

  /* ── HTML escape helper ────────────────────────────────────────── */
  window.esc = function(str){
    if(str===null||str===undefined) return '';
    var d=document.createElement('div');
    d.appendChild(document.createTextNode(String(str)));
    return d.innerHTML;
  };

  /* ── CSRF helpers ──────────────────────────────────────────────── */
  function csrfName(){ return $('meta[name="csrf-name"]').attr('content'); }
  function csrfToken(){ return $('meta[name="csrf-token"]').attr('content'); }
  function refreshCsrf(r){ if(r&&r.csrf_token) $('meta[name="csrf-token"]').attr('content',r.csrf_token); }

  /* ── Section select population ─────────────────────────────────── */
  function populateSections(classSelectId, sectionSelectId){
    var cls = $(classSelectId).val();
    var $sec = $(sectionSelectId);
    $sec.html('<option value="">All Sections</option>');
    if(cls && STRUCTURE[cls]){
      var sections = STRUCTURE[cls];
      for(var i=0;i<sections.length;i++){
        $sec.append('<option value="'+esc(sections[i])+'">Section '+esc(sections[i])+'</option>');
      }
    }
  }

  $(document).ready(function(){
    $('#epaClass').on('change',function(){ populateSections('#epaClass','#epaSection'); });
    $('#epaCmpClass').on('change',function(){ populateSections('#epaCmpClass','#epaCmpSection'); });
  });

  /* ═══════════════════════════════════════════════════════════════
     MAIN ANALYTICS
     ═══════════════════════════════════════════════════════════════ */
  window.analyzeExam = function(){
    var examId  = $('#epaExam').val();
    var cls     = $('#epaClass').val();
    var section = $('#epaSection').val();

    if(!examId || !cls){
      alert('Please select an exam and a class.');
      return;
    }

    var payload = {};
    payload[csrfName()] = csrfToken();
    payload.examId     = examId;
    payload.classKey   = cls;
    payload.sectionKey = section;

    $('#epaDashboard').hide();
    $('#epaSpinner').show();

    $.ajax({
      url: BASE_URL+'examination/get_analytics_data',
      type:'POST',
      data: payload,
      dataType:'json',
      success:function(r){
        refreshCsrf(r);
        $('#epaSpinner').hide();
        if(r.status==='success'){
          renderSummary(r);
          renderGradeChart(r.gradeDistribution);
          renderSubjectTable(r.subjectAnalysis);
          renderSectionComparison(r.sectionResults);
          $('#epaDashboard').fadeIn(200);
        } else {
          alert(r.message||'Failed to fetch analytics data.');
        }
      },
      error:function(){
        $('#epaSpinner').hide();
        alert('Network error. Please try again.');
      }
    });
  };

  /* ── Summary Cards ─────────────────────────────────────────────── */
  window.renderSummary = function(data){
    var avg = parseFloat(data.classAvg)||0;
    var passRate = parseFloat(data.passRate)||0;
    var total = parseInt(data.totalStudents)||0;
    var passed = parseInt(data.passCount)||0;
    var failed = total - passed;
    var grading = esc(data.gradingScale||'Standard');

    // gauge rotation: 0% = -90deg, 100% = 0deg
    var gaugeRot = -90 + (avg/100)*90;
    var avgColor = avg>=80?'#16a34a':avg>=60?'#0f766e':avg>=40?'#d97706':'#dc2626';

    var html = '';

    // Card 1 — Class Average
    html += '<div class="epa-card">'
          +   '<div class="epa-card-icon"><i class="fa fa-percent"></i></div>'
          +   '<div class="epa-card-value" style="color:'+avgColor+'">'+avg.toFixed(1)+'%</div>'
          +   '<div class="epa-card-label">Class Average</div>'
          +   '<div class="epa-gauge">'
          +     '<div class="epa-gauge-fill" style="background:'+avgColor+';transform:rotate('+gaugeRot+'deg)"></div>'
          +     '<div class="epa-gauge-mask"></div>'
          +   '</div>'
          + '</div>';

    // Card 2 — Pass Rate
    var prColor = passRate>=80?'#16a34a':passRate>=60?'#0f766e':passRate>=40?'#d97706':'#dc2626';
    html += '<div class="epa-card">'
          +   '<div class="epa-card-icon"><i class="fa fa-check-circle"></i></div>'
          +   '<div class="epa-card-value" style="color:'+prColor+'">'+passRate.toFixed(1)+'%</div>'
          +   '<div class="epa-card-label">Pass Rate</div>'
          +   '<div class="epa-card-sub">'+passed+' passed / '+failed+' failed</div>'
          + '</div>';

    // Card 3 — Total Students
    html += '<div class="epa-card">'
          +   '<div class="epa-card-icon"><i class="fa fa-users"></i></div>'
          +   '<div class="epa-card-value">'+total+'</div>'
          +   '<div class="epa-card-label">Total Students</div>'
          + '</div>';

    // Card 4 — Grading Scale
    html += '<div class="epa-card">'
          +   '<div class="epa-card-icon"><i class="fa fa-star-half-o"></i></div>'
          +   '<div class="epa-card-value" style="font-size:20px;">'+grading+'</div>'
          +   '<div class="epa-card-label">Grading Scale</div>'
          + '</div>';

    $('#epaSummaryCards').html(html);
  };

  /* ── Grade Distribution Bar Chart ──────────────────────────────── */
  window.renderGradeChart = function(gradeDistribution){
    if(!gradeDistribution || typeof gradeDistribution!=='object'){
      $('#epaGradePanel').hide();
      return;
    }

    var grades = Object.keys(gradeDistribution);
    if(!grades.length){ $('#epaGradePanel').hide(); return; }

    // Find max count
    var maxCount = 0;
    for(var i=0;i<grades.length;i++){
      var c = parseInt(gradeDistribution[grades[i]])||0;
      if(c>maxCount) maxCount=c;
    }
    if(maxCount===0) maxCount=1;

    // Color map
    var colorMap = {
      'A+':'epa-grade-aplus','A':'epa-grade-a','B+':'epa-grade-bplus','B':'epa-grade-b',
      'C':'epa-grade-c','D':'epa-grade-d','F':'epa-grade-f'
    };

    var html='';
    for(var i=0;i<grades.length;i++){
      var g = grades[i];
      var count = parseInt(gradeDistribution[g])||0;
      var pct = (count/maxCount)*100;
      var cls = colorMap[g]||'epa-grade-default';
      html += '<div class="epa-grade-row">'
            +   '<div class="epa-grade-label">'+esc(g)+'</div>'
            +   '<div class="epa-grade-bar-wrap">'
            +     '<div class="epa-grade-bar '+cls+'" style="width:'+pct+'%"><span>'+count+'</span></div>'
            +   '</div>'
            +   '<div class="epa-grade-count">'+count+'</div>'
            + '</div>';
    }

    $('#epaGradeChart').html(html);
    $('#epaGradePanel').show();
  };

  /* ── Subject Analysis Table (sortable) ─────────────────────────── */
  var subjectData = [];
  var sortCol = -1;
  var sortAsc = true;

  window.renderSubjectTable = function(subjectAnalysis){
    if(!subjectAnalysis || typeof subjectAnalysis!=='object'){
      $('#epaSubjectPanel').hide();
      return;
    }

    // Convert object {subject: {average,...}} to array [{subject, average,...}]
    var keys = Object.keys(subjectAnalysis);
    if(!keys.length){ $('#epaSubjectPanel').hide(); return; }

    subjectData = keys.map(function(k){
      var item = subjectAnalysis[k];
      item.subject = k;
      return item;
    });
    sortCol = -1;
    sortAsc = true;

    var cols = ['Subject','Average','Highest','Lowest','Pass Rate','Students'];
    var thead = '';
    for(var i=0;i<cols.length;i++){
      thead += '<th data-col="'+i+'">'+cols[i]+' <i class="fa fa-sort epa-sort-icon"></i></th>';
    }
    $('#epaSubjectTable thead tr').html(thead);

    renderSubjectRows();

    // Click sort
    $('#epaSubjectTable thead th').off('click').on('click',function(){
      var ci = parseInt($(this).attr('data-col'));
      if(sortCol===ci){ sortAsc=!sortAsc; }
      else{ sortCol=ci; sortAsc=true; }

      $('#epaSubjectTable thead th').removeClass('epa-sorted-asc epa-sorted-desc')
        .find('.epa-sort-icon').removeClass('fa-sort-asc fa-sort-desc').addClass('fa-sort');
      $(this).addClass(sortAsc?'epa-sorted-asc':'epa-sorted-desc')
        .find('.epa-sort-icon').removeClass('fa-sort').addClass(sortAsc?'fa-sort-asc':'fa-sort-desc');

      subjectData.sort(function(a,b){
        var keys=['subject','average','highest','lowest','passRate','students'];
        var va=a[keys[ci]], vb=b[keys[ci]];
        if(ci>0){va=parseFloat(va)||0;vb=parseFloat(vb)||0;}
        else{va=String(va).toLowerCase();vb=String(vb).toLowerCase();}
        if(va<vb) return sortAsc?-1:1;
        if(va>vb) return sortAsc?1:-1;
        return 0;
      });
      renderSubjectRows();
    });

    $('#epaSubjectPanel').show();
  };

  function renderSubjectRows(){
    var html='';
    for(var i=0;i<subjectData.length;i++){
      var s = subjectData[i];
      var pr = parseFloat(s.passRate)||0;
      var rateClass = pr>=80?'epa-rate-green':pr>=60?'epa-rate-teal':pr>=40?'epa-rate-amber':'epa-rate-red';
      html += '<tr>'
            +   '<td>'+esc(s.subject)+'</td>'
            +   '<td>'+parseFloat(s.average||0).toFixed(1)+'%</td>'
            +   '<td>'+parseFloat(s.highest||0).toFixed(1)+'%</td>'
            +   '<td>'+parseFloat(s.lowest||0).toFixed(1)+'%</td>'
            +   '<td><span class="epa-rate '+rateClass+'">'+pr.toFixed(1)+'%</span></td>'
            +   '<td>'+parseInt(s.students||0)+'</td>'
            + '</tr>';
    }
    $('#epaSubjectTable tbody').html(html);
  }

  /* ── Section Comparison ────────────────────────────────────────── */
  window.renderSectionComparison = function(sectionResults){
    if(!sectionResults || typeof sectionResults!=='object'){
      $('#epaSectionPanel').hide();
      return;
    }

    var keys = Object.keys(sectionResults);
    if(keys.length<2){ $('#epaSectionPanel').hide(); return; }

    // Find max avg — value can be a number or {avg, passRate, count} object
    var maxAvg = 0;
    for(var i=0;i<keys.length;i++){
      var val = sectionResults[keys[i]];
      var avg = (typeof val==='object' && val!==null) ? parseFloat(val.avg)||0 : parseFloat(val)||0;
      if(avg>maxAvg) maxAvg=avg;
    }
    if(maxAvg===0) maxAvg=1;

    var html='';
    for(var i=0;i<keys.length;i++){
      var sec = keys[i];
      var val = sectionResults[sec];
      var avg = (typeof val==='object' && val!==null) ? parseFloat(val.avg)||0 : parseFloat(val)||0;
      var pct = (avg/maxAvg)*100;
      html += '<div class="epa-section-row">'
            +   '<div class="epa-section-label">'+esc(sec)+'</div>'
            +   '<div class="epa-section-bar-wrap">'
            +     '<div class="epa-section-bar" style="width:'+pct.toFixed(1)+'%"><span>'+avg.toFixed(1)+'%</span></div>'
            +   '</div>'
            +   '<div class="epa-section-avg">'+avg.toFixed(1)+'%</div>'
            + '</div>';
    }

    $('#epaSectionChart').html(html);
    $('#epaSectionPanel').show();
  };

  /* ═══════════════════════════════════════════════════════════════
     EXAM COMPARISON
     ═══════════════════════════════════════════════════════════════ */
  window.compareExams = function(){
    var e1  = $('#epaExam1').val();
    var e2  = $('#epaExam2').val();
    var cls = $('#epaCmpClass').val();
    var sec = $('#epaCmpSection').val();

    if(!e1||!e2||!cls||!sec){
      alert('Please select both exams, a class, and a section.');
      return;
    }
    if(e1===e2){
      alert('Please select two different exams to compare.');
      return;
    }

    var payload = {};
    payload[csrfName()] = csrfToken();
    payload.examId1    = e1;
    payload.examId2    = e2;
    payload.classKey   = cls;
    payload.sectionKey = sec;

    $('#epaCmpResult').hide();
    $('#epaCmpSpinner').show();

    $.ajax({
      url: BASE_URL+'examination/get_exam_comparison',
      type:'POST',
      data: payload,
      dataType:'json',
      success:function(r){
        refreshCsrf(r);
        $('#epaCmpSpinner').hide();
        if(r.status==='success'){
          renderComparison(r);
          $('#epaCmpResult').fadeIn(200);
        } else {
          alert(r.message||'Failed to fetch comparison data.');
        }
      },
      error:function(){
        $('#epaCmpSpinner').hide();
        alert('Network error. Please try again.');
      }
    });
  };

  /* ── Render Comparison Tables ──────────────────────────────────── */
  window.renderComparison = function(data){
    // Student comparison
    var students = data.studentComparison||[];
    var sHtml='';
    for(var i=0;i<students.length;i++){
      var s = students[i];
      var e1 = s.exam1 ? parseFloat(s.exam1.percentage)||0 : 0;
      var e2 = s.exam2 ? parseFloat(s.exam2.percentage)||0 : 0;
      var diff = e2-e1;
      var chgClass,chgText;
      if(diff>0){ chgClass='epa-change-up'; chgText='<i class="fa fa-arrow-up"></i> +'+diff.toFixed(1)+'%'; }
      else if(diff<0){ chgClass='epa-change-down'; chgText='<i class="fa fa-arrow-down"></i> '+diff.toFixed(1)+'%'; }
      else{ chgClass='epa-change-flat'; chgText='0.0%'; }
      sHtml += '<tr>'
             +   '<td>'+esc(s.name)+'</td>'
             +   '<td>'+e1.toFixed(1)+'%</td>'
             +   '<td>'+e2.toFixed(1)+'%</td>'
             +   '<td><span class="'+chgClass+'">'+chgText+'</span></td>'
             + '</tr>';
    }
    $('#epaCmpStudentTable tbody').html(sHtml||'<tr><td colspan="4" style="text-align:center;color:var(--t3);">No student data available.</td></tr>');

    // Subject comparison — object keyed by subject name
    var subjectObj = data.subjectComparison||{};
    var subjectKeys = Object.keys(subjectObj);
    var subHtml='';
    for(var i=0;i<subjectKeys.length;i++){
      var s = subjectObj[subjectKeys[i]];
      s.subject = subjectKeys[i];
      var a1 = parseFloat(s.exam1Avg)||0;
      var a2 = parseFloat(s.exam2Avg)||0;
      var diff = a2-a1;
      var chgClass,chgText;
      if(diff>0){ chgClass='epa-change-up'; chgText='<i class="fa fa-arrow-up"></i> +'+diff.toFixed(1)+'%'; }
      else if(diff<0){ chgClass='epa-change-down'; chgText='<i class="fa fa-arrow-down"></i> '+diff.toFixed(1)+'%'; }
      else{ chgClass='epa-change-flat'; chgText='0.0%'; }
      subHtml += '<tr>'
               +   '<td>'+esc(s.subject)+'</td>'
               +   '<td>'+a1.toFixed(1)+'%</td>'
               +   '<td>'+a2.toFixed(1)+'%</td>'
               +   '<td><span class="'+chgClass+'">'+chgText+'</span></td>'
               + '</tr>';
    }
    $('#epaCmpSubjectTable tbody').html(subHtml||'<tr><td colspan="4" style="text-align:center;color:var(--t3);">No subject data available.</td></tr>');
  };

})();
});
</script>
