<?php defined('BASEPATH') OR exit('No direct script access allowed');
$configExams = $config['Exams'] ?? [];
?>

<div class="content-wrapper">
<div class="rcu-wrap">

  <!-- ── Page Header ──────────────────────────────────────────────── -->
  <div class="rcu-header">
    <div>
      <div class="rcu-page-title"><i class="fa fa-line-chart"></i> Cumulative Results</div>
      <ol class="rcu-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('result') ?>">Results</a></li>
        <li>Cumulative</li>
      </ol>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════════════════
       SECTION 1: Cumulative Config
  ═════════════════════════════════════════════════════════════════ -->
  <div class="rcu-section">
    <div class="rcu-section-title">
      <i class="fa fa-cog"></i> Exam Weights Configuration
      <small>Weights must sum to exactly 100</small>
    </div>
    <div class="rcu-config-box">
      <table class="rcu-config-table" id="rcuConfigTable">
        <thead>
          <tr>
            <th>Exam</th>
            <th>Label</th>
            <th>Weight (%)</th>
            <th></th>
          </tr>
        </thead>
        <tbody id="rcuConfigBody">
          <?php foreach ($exams as $ex):
            $weight = $configExams[$ex['id']]['Weight'] ?? 0;
            $label  = $configExams[$ex['id']]['Label']  ?? $ex['Name'] ?? $ex['id'];
          ?>
          <tr class="rcu-cfg-row" data-id="<?= htmlspecialchars($ex['id']) ?>">
            <td>
              <span class="rcu-exam-id"><?= htmlspecialchars($ex['id']) ?></span>
              <span class="rcu-exam-name"><?= htmlspecialchars($ex['Name'] ?? '') ?></span>
            </td>
            <td>
              <input type="text" class="rcu-input rcu-inp-label"
                     value="<?= htmlspecialchars($label) ?>"
                     placeholder="e.g. Mid-Term">
            </td>
            <td>
              <input type="number" class="rcu-input rcu-inp-weight"
                     min="0" max="100" value="<?= (int)$weight ?>"
                     placeholder="0">
            </td>
            <td>
              <span class="rcu-weight-help"></span>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($exams)): ?>
          <tr><td colspan="4" class="rcu-no-exams">No active exams found.</td></tr>
          <?php endif; ?>
        </tbody>
        <tfoot>
          <tr class="rcu-cfg-total-row">
            <td colspan="2" style="text-align:right;font-weight:700;color:var(--t2);">Total Weight:</td>
            <td id="rcuTotalWeight" style="font-weight:700;color:var(--gold);">0</td>
            <td></td>
          </tr>
        </tfoot>
      </table>
      <div class="rcu-config-actions">
        <span id="rcuCfgMsg" class="rcu-msg" style="display:none;"></span>
        <button id="rcuSaveCfgBtn" class="rcu-btn-primary">
          <i class="fa fa-save"></i> Save Configuration
        </button>
      </div>
    </div>
  </div>

  <!-- ════════════════════════════════════════════════════════════════
       SECTION 2: Compute & View Cumulative Result
  ═════════════════════════════════════════════════════════════════ -->
  <div class="rcu-section">
    <div class="rcu-section-title">
      <i class="fa fa-table"></i> Cumulative Result Table
    </div>

    <!-- Selectors -->
    <div class="rcu-selectors">
      <div class="rcu-form-group">
        <label class="rcu-label">Class</label>
        <select id="rcuClassSel" class="rcu-select">
          <option value="">-- Select Class --</option>
          <?php foreach ($structure as $ck => $sections): ?>
          <option value="<?= htmlspecialchars($ck) ?>"><?= htmlspecialchars($ck) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="rcu-form-group">
        <label class="rcu-label">Section</label>
        <select id="rcuSectionSel" class="rcu-select" disabled>
          <option value="">-- Select Section --</option>
        </select>
      </div>
      <button id="rcuComputeBtn" class="rcu-btn-outline" disabled>
        <i class="fa fa-calculator"></i> Compute Cumulative
      </button>
      <button id="rcuLoadBtn" class="rcu-btn-primary" disabled>
        <i class="fa fa-search"></i> Load Results
      </button>
    </div>

    <div id="rcuComputeMsg" class="rcu-compute-msg" style="display:none;"></div>

    <div id="rcuLoading"  style="display:none;" class="rcu-loading">
      <i class="fa fa-spinner fa-spin"></i> Loading cumulative results…
    </div>
    <div id="rcuEmpty" style="display:none;" class="rcu-empty">
      <i class="fa fa-inbox"></i>
      <p>No cumulative results yet. Configure weights, save, then click <strong>Compute Cumulative</strong>.</p>
    </div>
    <div id="rcuTableWrap" style="display:none;">
      <div class="rcu-table-toolbar">
        <input type="text" id="rcuSearch" class="rcu-search" placeholder="Search student…">
        <span id="rcuCount" class="rcu-count"></span>
        <button class="rcu-btn-sm" onclick="window.print()"><i class="fa fa-print"></i> Print</button>
      </div>
      <div class="rcu-table-outer">
        <table class="rcu-table">
          <thead id="rcuThead"></thead>
          <tbody id="rcuTbody"></tbody>
        </table>
      </div>
    </div>
  </div>

</div><!-- /.rcu-wrap -->
</div><!-- /.content-wrapper -->

<script>
(function () {
  'use strict';

  var STRUCTURE = <?= json_encode($structure) ?>;

  // ── Config section ─────────────────────────────────────────────────
  var weightInputs = document.querySelectorAll('.rcu-inp-weight');
  var totalWeightEl = document.getElementById('rcuTotalWeight');

  function recalcWeights() {
    var total = 0;
    weightInputs.forEach(function (inp) { total += parseInt(inp.value) || 0; });
    totalWeightEl.textContent = total;
    totalWeightEl.style.color = total === 100 ? '#16a34a' : (total > 100 ? '#dc2626' : 'var(--gold)');
  }

  weightInputs.forEach(function (inp) {
    inp.addEventListener('input', recalcWeights);
  });
  recalcWeights();

  document.getElementById('rcuSaveCfgBtn').addEventListener('click', function () {
    var examsConfig = {};
    document.querySelectorAll('.rcu-cfg-row').forEach(function (tr) {
      var id     = tr.dataset.id;
      var label  = tr.querySelector('.rcu-inp-label').value.trim();
      var weight = parseInt(tr.querySelector('.rcu-inp-weight').value) || 0;
      examsConfig[id] = { label: label, weight: weight };
    });

    var total = Object.values(examsConfig).reduce(function (s, e) { return s + e.weight; }, 0);
    if (total !== 100) {
      showMsg('rcuCfgMsg', 'Total weight must be exactly 100 (currently ' + total + ').', true);
      return;
    }

    var btn = this;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Saving…';

    var fd = new FormData();
    fd.append('config', JSON.stringify({ exams: examsConfig }));
    fd.append('<?= $this->security->get_csrf_token_name() ?>', '<?= $this->security->get_csrf_hash() ?>');

    fetch('<?= base_url('result/save_cumulative_config') ?>', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-save"></i> Save Configuration';
        showMsg('rcuCfgMsg', d.success ? d.message : (d.message || 'Error'), !d.success);
      })
      .catch(function () {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-save"></i> Save Configuration';
        showMsg('rcuCfgMsg', 'Network error.', true);
      });
  });

  // ── Class/Section selectors ─────────────────────────────────────────
  var classSel   = document.getElementById('rcuClassSel');
  var sectionSel = document.getElementById('rcuSectionSel');
  var computeBtn = document.getElementById('rcuComputeBtn');
  var loadBtn    = document.getElementById('rcuLoadBtn');
  var loading    = document.getElementById('rcuLoading');
  var emptyDiv   = document.getElementById('rcuEmpty');
  var tableWrap  = document.getElementById('rcuTableWrap');
  var computeMsg = document.getElementById('rcuComputeMsg');
  var searchInp  = document.getElementById('rcuSearch');
  var countEl    = document.getElementById('rcuCount');
  var thead      = document.getElementById('rcuThead');
  var tbody      = document.getElementById('rcuTbody');

  function checkReady() {
    var ok = classSel.value && sectionSel.value;
    computeBtn.disabled = !ok;
    loadBtn.disabled    = !ok;
  }

  classSel.addEventListener('change', function () {
    var sections = STRUCTURE[this.value] || [];
    sectionSel.innerHTML = '<option value="">-- Select Section --</option>';
    sections.forEach(function (s) {
      var opt = document.createElement('option');
      opt.value = 'Section ' + s; opt.textContent = 'Section ' + s;
      sectionSel.appendChild(opt);
    });
    sectionSel.disabled = !sections.length; checkReady();
  });
  sectionSel.addEventListener('change', checkReady);

  computeBtn.addEventListener('click', function () {
    computeBtn.disabled = true;
    computeBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Computing…';

    var fd = new FormData();
    fd.append('classKey',   classSel.value);
    fd.append('sectionKey', sectionSel.value);
    fd.append('<?= $this->security->get_csrf_token_name() ?>', '<?= $this->security->get_csrf_hash() ?>');

    fetch('<?= base_url('result/compute_cumulative') ?>', { method: 'POST', body: fd })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        computeBtn.disabled = false;
        computeBtn.innerHTML = '<i class="fa fa-calculator"></i> Compute Cumulative';
        showComputeMsg(d.success ? d.message : (d.message || 'Error'), !d.success);
        if (d.success) loadCumulative();
      })
      .catch(function () {
        computeBtn.disabled = false;
        computeBtn.innerHTML = '<i class="fa fa-calculator"></i> Compute Cumulative';
        showComputeMsg('Network error.', true);
      });
  });

  loadBtn.addEventListener('click', loadCumulative);

  function loadCumulative() {
    loading.style.display   = '';
    emptyDiv.style.display  = 'none';
    tableWrap.style.display = 'none';

    fetch('<?= base_url('result/get_cumulative_data') ?>?classKey=' + encodeURIComponent(classSel.value)
        + '&sectionKey=' + encodeURIComponent(sectionSel.value))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        loading.style.display = 'none';
        if (!d.students || !d.students.length) {
          emptyDiv.style.display = '';
          return;
        }
        renderTable(d.students, d.subjects || []);
      })
      .catch(function () {
        loading.style.display = 'none';
        emptyDiv.style.display = '';
      });
  }

  function renderTable(students, subjects) {
    thead.innerHTML = '';
    var trH = document.createElement('tr');
    ['Rank','Name'].concat(subjects.map(function (s) { return s + ' (Wt.)'; }))
      .concat(['Weighted %', 'Grade', 'P/F']).forEach(function (col) {
        var th = document.createElement('th');
        th.textContent = col; th.className = 'rcu-th';
        trH.appendChild(th);
      });
    thead.appendChild(trH);

    tbody.innerHTML = '';
    students.forEach(function (stu) {
      var tr = document.createElement('tr');
      tr.className = 'rcu-row';
      tr.dataset.name = (stu.name || '').toLowerCase();

      function cell(v, cls) {
        var td = document.createElement('td');
        td.textContent = v != null ? v : '—';
        if (cls) td.className = cls;
        return td;
      }

      tr.appendChild(cell(stu.rank, 'rcu-td-rank'));
      tr.appendChild(cell(stu.name, 'rcu-td-name'));

      subjects.forEach(function (s) {
        var sd = (stu.subjects || {})[s] || {};
        tr.appendChild(cell(
          sd.WeightedScore != null ? sd.WeightedScore.toFixed(1) : '—',
          'rcu-td-subj'
        ));
      });

      tr.appendChild(cell(stu.weightedTotal != null ? stu.weightedTotal.toFixed(2) + '%' : '—', 'rcu-td-pct'));
      tr.appendChild(cell(stu.grade, 'rcu-td-grade'));
      var pfTd = cell(stu.passFail, 'rcu-td-pf');
      pfTd.classList.add(stu.passFail === 'Pass' ? 'rcu-pf-pass' : 'rcu-pf-fail');
      tr.appendChild(pfTd);

      tbody.appendChild(tr);
    });

    tableWrap.style.display = '';
    updateCount();
  }

  searchInp.addEventListener('input', function () {
    var q = this.value.toLowerCase();
    Array.from(tbody.querySelectorAll('.rcu-row')).forEach(function (tr) {
      tr.style.display = (!q || (tr.dataset.name || '').indexOf(q) !== -1) ? '' : 'none';
    });
    updateCount();
  });

  function updateCount() {
    var v = Array.from(tbody.querySelectorAll('.rcu-row')).filter(function (r) { return r.style.display !== 'none'; }).length;
    countEl.textContent = v + ' student' + (v !== 1 ? 's' : '');
  }

  function showMsg(elId, msg, isErr) {
    var el = document.getElementById(elId);
    el.textContent = msg;
    el.className   = 'rcu-msg ' + (isErr ? 'rcu-msg-err' : 'rcu-msg-ok');
    el.style.display = '';
    setTimeout(function () { el.style.display = 'none'; }, 5000);
  }

  function showComputeMsg(msg, isErr) {
    computeMsg.textContent   = msg;
    computeMsg.className     = 'rcu-compute-msg ' + (isErr ? 'rcu-msg-err' : 'rcu-msg-ok');
    computeMsg.style.display = '';
    setTimeout(function () { computeMsg.style.display = 'none'; }, 5000);
  }
})();
</script>

<style>
html { font-size: 16px !important; }

/* ═══════════════════════════════════════════════════════════
   Cumulative — .rcu-*
═══════════════════════════════════════════════════════════ */
.rcu-wrap { max-width: 1200px; margin: 0 auto; padding: 24px 16px 56px; }

.rcu-header { margin-bottom: 24px; }
.rcu-page-title { font-size: 1.4rem; font-weight: 700; color: var(--t1); display: flex; align-items: center; gap: 10px; margin-bottom: 4px; }
.rcu-page-title i { color: var(--gold); }
.rcu-breadcrumb { list-style: none; margin: 0; padding: 0; display: flex; gap: 6px; font-size: .83rem; color: var(--t3); }
.rcu-breadcrumb li + li::before { content: '›'; margin-right: 6px; }
.rcu-breadcrumb a { color: var(--gold); text-decoration: none; }

/* Sections */
.rcu-section { margin-bottom: 36px; }
.rcu-section-title {
  font-size: 1.05rem; font-weight: 700; color: var(--t2); margin-bottom: 16px;
  display: flex; align-items: center; gap: 10px;
}
.rcu-section-title i { color: var(--gold); }
.rcu-section-title small { font-size: .8rem; font-weight: 400; color: var(--t3); }

/* Config */
.rcu-config-box { background: var(--bg2); border: 1px solid var(--border); border-radius: 12px; overflow: hidden; }
.rcu-config-table { width: 100%; border-collapse: collapse; font-size: .88rem; }
.rcu-config-table th {
  background: var(--bg3); color: var(--t2); padding: 10px 14px;
  border-bottom: 2px solid var(--border); font-weight: 700; text-align: left;
}
.rcu-config-table td { padding: 9px 14px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.rcu-config-table tfoot td { background: var(--bg3); padding: 10px 14px; }
.rcu-exam-id { font-size: .78rem; font-family: monospace; color: var(--t3); margin-right: 6px; }
.rcu-exam-name { font-weight: 600; color: var(--t1); }
.rcu-input {
  width: 100%; padding: 7px 9px; border: 1px solid var(--border); border-radius: 7px;
  background: var(--bg); color: var(--t1); font-size: .88rem;
}
.rcu-input:focus { border-color: var(--gold); outline: none; }
.rcu-inp-weight { width: 80px; text-align: center; }
.rcu-config-actions {
  padding: 14px 16px; display: flex; align-items: center; gap: 14px;
  border-top: 1px solid var(--border);
}
.rcu-no-exams { text-align: center; padding: 20px; color: var(--t3); }

/* Selectors */
.rcu-selectors {
  display: flex; flex-wrap: wrap; gap: 14px; align-items: flex-end;
  background: var(--bg2); border: 1px solid var(--border); border-radius: 12px;
  padding: 18px 20px; margin-bottom: 20px;
}
.rcu-form-group { flex: 1; min-width: 160px; }
.rcu-label { display: block; font-size: .82rem; font-weight: 600; color: var(--t2); margin-bottom: 5px; }
.rcu-select {
  width: 100%; padding: 8px 10px; border: 1px solid var(--border); border-radius: 7px;
  background: var(--bg3); color: var(--t1); font-size: .88rem;
}
.rcu-btn-primary {
  display: inline-flex; align-items: center; gap: 7px; padding: 9px 20px;
  background: var(--gold); color: #fff; border: none; border-radius: 8px;
  font-size: .9rem; font-weight: 600; cursor: pointer; align-self: flex-end;
}
.rcu-btn-primary:hover { background: var(--gold2); }
.rcu-btn-primary:disabled { opacity: .5; cursor: not-allowed; }
.rcu-btn-outline {
  display: inline-flex; align-items: center; gap: 7px; padding: 9px 20px;
  background: transparent; color: var(--gold); border: 1.5px solid var(--gold);
  border-radius: 8px; font-size: .9rem; font-weight: 600; cursor: pointer; align-self: flex-end;
}
.rcu-btn-outline:hover { background: var(--gold-dim); }
.rcu-btn-outline:disabled { opacity: .5; cursor: not-allowed; }

/* Table toolbar */
.rcu-table-toolbar { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 14px; }
.rcu-search {
  flex: 1; min-width: 200px; padding: 8px 12px; border: 1px solid var(--border);
  border-radius: 8px; background: var(--bg2); color: var(--t1); font-size: .88rem;
}
.rcu-search:focus { border-color: var(--gold); outline: none; }
.rcu-count { font-size: .82rem; color: var(--t3); white-space: nowrap; }
.rcu-btn-sm {
  display: inline-flex; align-items: center; gap: 6px; padding: 7px 16px;
  background: var(--bg3); border: 1px solid var(--border); border-radius: 7px;
  color: var(--t2); font-size: .85rem; cursor: pointer;
}
.rcu-btn-sm:hover { background: var(--gold-dim); color: var(--gold); }

/* Table */
.rcu-table-outer { overflow-x: auto; border: 1px solid var(--border); border-radius: 12px; }
.rcu-table { width: 100%; border-collapse: collapse; font-size: .84rem; min-width: 600px; }
.rcu-th {
  background: var(--bg3); color: var(--t2); padding: 10px; text-align: center;
  border-bottom: 2px solid var(--border); font-weight: 700; white-space: nowrap; position: sticky; top: 0;
}
.rcu-row td { padding: 8px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
.rcu-row:last-child td { border-bottom: none; }
.rcu-row:hover td { background: var(--gold-dim); }
.rcu-td-rank  { text-align: center; font-weight: 700; color: var(--gold); }
.rcu-td-name  { font-weight: 600; color: var(--t1); }
.rcu-td-subj  { text-align: center; }
.rcu-td-pct   { text-align: center; font-weight: 600; }
.rcu-td-grade { text-align: center; font-weight: 700; color: var(--gold); }
.rcu-td-pf    { text-align: center; font-weight: 700; }
.rcu-pf-pass  { color: #16a34a; }
.rcu-pf-fail  { color: #dc2626; }

/* Messages */
.rcu-msg         { font-size: .88rem; padding: 7px 14px; border-radius: 7px; }
.rcu-msg-ok      { background: rgba(22,163,74,.1); color: #16a34a; }
.rcu-msg-err     { background: rgba(239,68,68,.1); color: #dc2626; }
.rcu-compute-msg { padding: 10px 14px; border-radius: 8px; font-size: .88rem; margin-bottom: 14px; }

/* States */
.rcu-loading { text-align: center; padding: 40px; color: var(--t3); font-size: 1.05rem; }
.rcu-empty   { text-align: center; padding: 40px; color: var(--t3); }
.rcu-empty i { font-size: 2.5rem; color: var(--border); display: block; margin-bottom: 12px; }

@media print {
  .rcu-header, .rcu-section:first-child, .rcu-selectors, .rcu-table-toolbar, .rcu-compute-msg { display: none !important; }
  .rcu-wrap { padding: 0; }
  .rcu-table-outer { border: none; }
}
</style>
