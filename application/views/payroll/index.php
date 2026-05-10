<?php defined('BASEPATH') OR exit('No direct script access allowed');
/**
 * Payroll Stage 3 UI — single-file operator interface for posting payroll
 * journals via PayrollAccountingService.
 *
 * Architectural property: this view does NOT contain accounting logic.
 * Every commit goes through the controller → service → engine. The
 * preview pane is read-only and does not affect ledger state.
 */
?>
<div class="content-wrapper">
<section class="content" style="padding: 16px;">
<div class="payroll-wrap" style="max-width: 1200px; margin: 0 auto; font-family: 'Satoshi', sans-serif;">

  <!-- Header -->
  <div class="payroll-header" style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; padding: 12px 16px; background: #fff; border-radius: 8px; border: 1px solid #e5e7eb;">
    <div>
      <h2 style="margin: 0; color: #111;"><i class="fa fa-money"></i> Payroll Posting</h2>
      <ol class="breadcrumb" style="margin: 4px 0 0; padding: 0; font-size: 12px; color: #666; list-style: none;">
        <li style="display:inline;"><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li style="display:inline;"> &raquo; Payroll</li>
      </ol>
    </div>
    <div style="text-align: right; font-size: 12px;">
      <div><strong>School:</strong> <?= htmlspecialchars($school_name) ?></div>
      <div><strong>Session:</strong> <?= htmlspecialchars($session_year) ?></div>
      <div>
        <strong>Engine flag:</strong>
        <?php if ($flag_enabled): ?>
          <span style="color: #16a34a;">ENABLED</span>
        <?php else: ?>
          <span style="color: #dc2626;">DISABLED</span>
          <small style="display:block;">Set PAYROLL_ENGINE_INTEGRATION=1</small>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if (!$flag_enabled): ?>
  <div style="background: #fef2f2; border: 1px solid #fecaca; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; color: #991b1b;">
    <strong>Payroll engine integration is disabled.</strong>
    Set <code>PAYROLL_ENGINE_INTEGRATION=1</code> in the environment before invoking the post endpoints.
    Preview endpoints work without the flag (read-only computations).
  </div>
  <?php endif; ?>

  <!-- Tabs -->
  <div class="payroll-tabs" style="display: flex; gap: 0; margin-bottom: 0; border-bottom: 2px solid #e5e7eb;">
    <button type="button" class="pr-tab active" data-tab="accrual" onclick="prShowTab('accrual')" style="padding: 10px 18px; border: none; background: none; font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent;">Accrual</button>
    <button type="button" class="pr-tab" data-tab="payout" onclick="prShowTab('payout')" style="padding: 10px 18px; border: none; background: none; font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent;">Payout</button>
    <button type="button" class="pr-tab" data-tab="statutory" onclick="prShowTab('statutory')" style="padding: 10px 18px; border: none; background: none; font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent;">Statutory Deposit</button>
    <button type="button" class="pr-tab" data-tab="reversal" onclick="prShowTab('reversal')" style="padding: 10px 18px; border: none; background: none; font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent;">Reversal</button>
    <button type="button" class="pr-tab" data-tab="recent" onclick="prShowTab('recent'); prLoadRecent()" style="padding: 10px 18px; border: none; background: none; font-weight: 600; cursor: pointer; border-bottom: 3px solid transparent; margin-left: auto;">Recent Posts</button>
  </div>

  <!-- ============ ACCRUAL TAB ============ -->
  <div class="pr-panel" id="pr-accrual" style="background: #fff; border: 1px solid #e5e7eb; border-top: none; padding: 20px; border-radius: 0 0 8px 8px;">
    <h4 style="margin-top: 0;">Post Salary Accrual</h4>
    <p style="color: #666; font-size: 13px;">Recognize gross salary expense + create payable liabilities for one employee for one period.</p>

    <form id="pr-form-accrual" onsubmit="event.preventDefault(); return false;">
      <div class="row">
        <div class="col-md-3"><label>Employee ID *</label><input type="text" name="employee_id" class="form-control" required></div>
        <div class="col-md-4"><label>Employee Name *</label><input type="text" name="employee_name" class="form-control" required></div>
        <div class="col-md-2">
          <label>Role Class *</label>
          <select name="role_class" class="form-control">
            <option value="teaching">Teaching</option>
            <option value="non_teaching">Non-Teaching</option>
            <option value="admin">Admin</option>
            <option value="support">Support</option>
          </select>
        </div>
        <div class="col-md-3"><label>Period Label (YYYY-MM) *</label><input type="text" name="period_label" class="form-control" placeholder="2026-04" pattern="\d{4}-\d{2}" required></div>
      </div>
      <div class="row" style="margin-top: 8px;">
        <div class="col-md-3"><label>Period Start</label><input type="date" name="period_start" class="form-control"></div>
        <div class="col-md-3"><label>Period End</label><input type="date" name="period_end" class="form-control"></div>
        <div class="col-md-3"><label>Gross Salary *</label><input type="number" name="gross_salary" class="form-control" step="0.01" min="0" required></div>
        <div class="col-md-3"><label>Net Take-Home *</label><input type="number" name="net_take_home" class="form-control" step="0.01" min="0" required></div>
      </div>

      <h5 style="margin-top: 16px; color: #444;">Deductions (employee-side)</h5>
      <div class="row">
        <div class="col-md-2"><label>PF Employee</label><input type="number" name="ded_pf_employee" class="form-control" step="0.01" value="0"></div>
        <div class="col-md-2"><label>ESI Employee</label><input type="number" name="ded_esi_employee" class="form-control" step="0.01" value="0"></div>
        <div class="col-md-2"><label>TDS</label><input type="number" name="ded_tds" class="form-control" step="0.01" value="0"></div>
        <div class="col-md-2"><label>Prof. Tax</label><input type="number" name="ded_prof_tax" class="form-control" step="0.01" value="0"></div>
        <div class="col-md-2"><label>Other</label><input type="number" name="ded_other" class="form-control" step="0.01" value="0"></div>
      </div>

      <h5 style="margin-top: 16px; color: #444;">Employer Contributions</h5>
      <div class="row">
        <div class="col-md-3"><label>PF Employer</label><input type="number" name="emp_pf_employer" class="form-control" step="0.01" value="0"></div>
        <div class="col-md-3"><label>ESI Employer</label><input type="number" name="emp_esi_employer" class="form-control" step="0.01" value="0"></div>
        <div class="col-md-3"><label>Journal Date</label><input type="date" name="journal_date" class="form-control"></div>
      </div>

      <div style="margin-top: 16px; display: flex; gap: 8px;">
        <button type="button" class="btn btn-default" onclick="prPreview('accrual')">Preview Journal</button>
        <button type="button" class="btn btn-primary" onclick="prPost('accrual')" <?= $flag_enabled ? '' : 'disabled' ?>>Confirm &amp; Post</button>
      </div>
    </form>
    <div id="pr-result-accrual" style="margin-top: 16px;"></div>
  </div>

  <!-- ============ PAYOUT TAB ============ -->
  <div class="pr-panel" id="pr-payout" style="display: none; background: #fff; border: 1px solid #e5e7eb; border-top: none; padding: 20px; border-radius: 0 0 8px 8px;">
    <h4 style="margin-top: 0;">Post Salary Payout</h4>
    <p style="color: #666; font-size: 13px;">Extinguishes Salary Payable for one employee+period via cash/bank.</p>
    <form id="pr-form-payout" onsubmit="event.preventDefault(); return false;">
      <div class="row">
        <div class="col-md-3"><label>Employee ID *</label><input type="text" name="employee_id" class="form-control" required></div>
        <div class="col-md-3"><label>Period Label *</label><input type="text" name="period_label" class="form-control" placeholder="2026-04" pattern="\d{4}-\d{2}" required></div>
        <div class="col-md-3"><label>Amount *</label><input type="number" name="amount" class="form-control" step="0.01" min="0" required></div>
        <div class="col-md-3">
          <label>Mode *</label>
          <select name="mode" class="form-control">
            <option value="cash">Cash</option>
            <option value="bank">Bank Transfer</option>
            <option value="cheque">Cheque</option>
            <option value="upi">UPI</option>
            <option value="neft">NEFT</option>
            <option value="rtgs">RTGS</option>
            <option value="online">Online</option>
          </select>
        </div>
      </div>
      <div class="row" style="margin-top: 8px;">
        <div class="col-md-3"><label>Bank Code (non-cash)</label><input type="text" name="bank_code" class="form-control" placeholder="e.g. 1020"></div>
        <div class="col-md-3"><label>Journal Date</label><input type="date" name="journal_date" class="form-control"></div>
      </div>
      <div style="margin-top: 16px; display: flex; gap: 8px;">
        <button type="button" class="btn btn-default" onclick="prPreview('payout')">Preview Journal</button>
        <button type="button" class="btn btn-primary" onclick="prPost('payout')" <?= $flag_enabled ? '' : 'disabled' ?>>Confirm &amp; Post</button>
      </div>
    </form>
    <div id="pr-result-payout" style="margin-top: 16px;"></div>
  </div>

  <!-- ============ STATUTORY DEPOSIT TAB ============ -->
  <div class="pr-panel" id="pr-statutory" style="display: none; background: #fff; border: 1px solid #e5e7eb; border-top: none; padding: 20px; border-radius: 0 0 8px 8px;">
    <h4 style="margin-top: 0;">Post Statutory Deposit</h4>
    <p style="color: #666; font-size: 13px;">Remit accumulated PF/ESI/TDS/PT/other deduction liabilities to statutory authorities.</p>
    <form id="pr-form-statutory" onsubmit="event.preventDefault(); return false;">
      <div class="row">
        <div class="col-md-3"><label>Period Label *</label><input type="text" name="period_label" class="form-control" placeholder="2026-04" required></div>
        <div class="col-md-3">
          <label>Account Code *</label>
          <select name="account_code" class="form-control">
            <option value="2030">2030 — PF Payable</option>
            <option value="2031">2031 — ESI Payable</option>
            <option value="2032">2032 — TDS Payable</option>
            <option value="2033">2033 — Prof. Tax Payable</option>
            <option value="2034">2034 — Other Deductions Payable</option>
          </select>
        </div>
        <div class="col-md-3"><label>Amount *</label><input type="number" name="amount" class="form-control" step="0.01" min="0" required></div>
        <div class="col-md-3">
          <label>Mode *</label>
          <select name="mode" class="form-control">
            <option value="bank">Bank Transfer</option>
            <option value="cheque">Cheque</option>
            <option value="neft">NEFT</option>
            <option value="rtgs">RTGS</option>
            <option value="online">Online</option>
            <option value="cash">Cash</option>
          </select>
        </div>
      </div>
      <div class="row" style="margin-top: 8px;">
        <div class="col-md-3"><label>Bank Code (non-cash)</label><input type="text" name="bank_code" class="form-control" placeholder="e.g. 1020"></div>
        <div class="col-md-3"><label>Journal Date</label><input type="date" name="journal_date" class="form-control"></div>
      </div>
      <div style="margin-top: 16px; display: flex; gap: 8px;">
        <button type="button" class="btn btn-default" onclick="prPreview('statutory')">Preview Journal</button>
        <button type="button" class="btn btn-primary" onclick="prPost('statutory')" <?= $flag_enabled ? '' : 'disabled' ?>>Confirm &amp; Post</button>
      </div>
    </form>
    <div id="pr-result-statutory" style="margin-top: 16px;"></div>
  </div>

  <!-- ============ REVERSAL TAB ============ -->
  <div class="pr-panel" id="pr-reversal" style="display: none; background: #fff; border: 1px solid #e5e7eb; border-top: none; padding: 20px; border-radius: 0 0 8px 8px;">
    <h4 style="margin-top: 0;">Initiate Payroll Reversal</h4>
    <p style="color: #666; font-size: 13px;">Posts a Dr/Cr-swapped journal that nets the original to zero. The original remains in the ledger immutably.</p>
    <form id="pr-form-reversal" onsubmit="event.preventDefault(); return false;">
      <div class="row">
        <div class="col-md-6"><label>Original Entry ID *</label><input type="text" name="original_entry_id" class="form-control" placeholder="JE_PAYROLL_ACCRUAL_2026-04_EMP_001" required></div>
        <div class="col-md-3"><label>Journal Date</label><input type="date" name="journal_date" class="form-control"></div>
      </div>
      <div class="row" style="margin-top: 8px;">
        <div class="col-md-12"><label>Reason * (audit trail)</label><textarea name="reason" class="form-control" rows="2" required></textarea></div>
      </div>
      <div style="margin-top: 16px; display: flex; gap: 8px;">
        <button type="button" class="btn btn-warning" onclick="prPostReversal()" <?= $flag_enabled ? '' : 'disabled' ?>>Confirm &amp; Post Reversal</button>
      </div>
    </form>
    <div id="pr-result-reversal" style="margin-top: 16px;"></div>
  </div>

  <!-- ============ RECENT POSTS TAB ============ -->
  <div class="pr-panel" id="pr-recent" style="display: none; background: #fff; border: 1px solid #e5e7eb; border-top: none; padding: 20px; border-radius: 0 0 8px 8px;">
    <div style="display: flex; justify-content: space-between; align-items: center;">
      <h4 style="margin-top: 0;">Recent Payroll Journals</h4>
      <button type="button" class="btn btn-default btn-sm" onclick="prLoadRecent()"><i class="fa fa-refresh"></i> Reload</button>
    </div>
    <div id="pr-recent-table" style="margin-top: 12px;">
      <div style="color: #888;">Click Reload to fetch the last 50 payroll journals.</div>
    </div>
  </div>

</div>
</section>
</div>

<style>
  .pr-tab { color: #555; }
  .pr-tab.active { color: #2563eb; border-bottom-color: #2563eb !important; }
  .pr-tab:hover { color: #2563eb; }
  .pr-line-row { border-bottom: 1px solid #f0f0f0; padding: 4px 0; font-family: monospace; font-size: 13px; }
  .pr-result-ok { background: #f0fdf4; border: 1px solid #bbf7d0; padding: 10px; border-radius: 6px; color: #166534; }
  .pr-result-err { background: #fef2f2; border: 1px solid #fecaca; padding: 10px; border-radius: 6px; color: #991b1b; }
  .pr-result-preview { background: #eff6ff; border: 1px solid #bfdbfe; padding: 10px; border-radius: 6px; color: #1e3a8a; font-family: monospace; font-size: 13px; }
  .pr-recent-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .pr-recent-table th, .pr-recent-table td { padding: 6px 8px; border-bottom: 1px solid #e5e7eb; text-align: left; }
  .pr-recent-table th { background: #f9fafb; font-weight: 600; }
  .pr-recent-table tr:hover { background: #f9fafb; }
</style>

<script>
(function() {
  const BASE = '<?= base_url('payroll') ?>';

  window.prShowTab = function(tab) {
    document.querySelectorAll('.pr-tab').forEach(t => t.classList.toggle('active', t.dataset.tab === tab));
    document.querySelectorAll('.pr-panel').forEach(p => { p.style.display = (p.id === 'pr-' + tab) ? 'block' : 'none'; });
  };

  function formData(formId) {
    const fd = new FormData(document.getElementById(formId));
    const obj = {};
    fd.forEach((v, k) => obj[k] = v);
    return obj;
  }

  function postForm(url, body) {
    const fd = new FormData();
    Object.entries(body).forEach(([k, v]) => fd.append(k, v));
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(r => r.json().then(j => ({ status: r.status, body: j })));
  }

  function renderPreview(target, preview) {
    const lines = (preview.lines || []).map(l =>
      `<div class="pr-line-row">${l.account_code.padEnd(6)}  Dr ${String(l.dr).padStart(10)}  Cr ${String(l.cr).padStart(10)}  ${l.narration}</div>`
    ).join('');
    target.innerHTML = `
      <div class="pr-result-preview">
        <div><strong>Preview — would post (NOT yet committed):</strong></div>
        <div>Source: <code>${preview.source}</code> | Source Ref: <code>${preview.source_ref}</code></div>
        <div>Expected Entry ID: <code>${preview.expected_entry_id}</code></div>
        <div>Journal Date: ${preview.journal_date}</div>
        <div style="margin-top: 8px;">${lines}</div>
        <div style="margin-top: 8px;"><strong>Total Dr: ${preview.total_dr}</strong> | <strong>Total Cr: ${preview.total_cr}</strong></div>
      </div>`;
  }

  function renderError(target, msg) {
    target.innerHTML = `<div class="pr-result-err"><strong>Error:</strong> ${msg}</div>`;
  }

  function renderSuccess(target, body) {
    target.innerHTML = `<div class="pr-result-ok"><strong>Posted.</strong> Entry ID: <code>${body.entry_id || '?'}</code> | Event: <code>${body.event || '?'}</code></div>`;
  }

  window.prPreview = function(tab) {
    const target = document.getElementById('pr-result-' + tab);
    const body = formData('pr-form-' + tab);
    const url = BASE + '/preview_' + (tab === 'statutory' ? 'statutory' : tab);
    target.innerHTML = '<em>Computing preview...</em>';
    postForm(url, body).then(({ status, body: j }) => {
      if (status >= 200 && status < 300 && j && j.success) renderPreview(target, j.data ? j.data.preview : j.preview);
      else renderError(target, (j && (j.message || j.error)) || ('HTTP ' + status));
    }).catch(e => renderError(target, String(e)));
  };

  window.prPost = function(tab) {
    if (!confirm('Confirm posting this ' + tab + ' to the ledger?')) return;
    const target = document.getElementById('pr-result-' + tab);
    const body = formData('pr-form-' + tab);
    const url = BASE + '/post_' + (tab === 'statutory' ? 'statutory_deposit' : tab);
    target.innerHTML = '<em>Posting...</em>';
    postForm(url, body).then(({ status, body: j }) => {
      if (status >= 200 && status < 300 && j && j.success) renderSuccess(target, j.data || j);
      else renderError(target, (j && (j.message || j.error)) || ('HTTP ' + status));
    }).catch(e => renderError(target, String(e)));
  };

  window.prPostReversal = function() {
    const body = formData('pr-form-reversal');
    if (!body.original_entry_id || !body.reason) { alert('Original entry ID and reason are required.'); return; }
    if (!confirm('Confirm reversal of ' + body.original_entry_id + '?')) return;
    const target = document.getElementById('pr-result-reversal');
    target.innerHTML = '<em>Posting reversal...</em>';
    postForm(BASE + '/post_reversal', body).then(({ status, body: j }) => {
      if (status >= 200 && status < 300 && j && j.success) renderSuccess(target, j.data || j);
      else renderError(target, (j && (j.message || j.error)) || ('HTTP ' + status));
    }).catch(e => renderError(target, String(e)));
  };

  window.prLoadRecent = function() {
    const target = document.getElementById('pr-recent-table');
    target.innerHTML = '<em>Loading...</em>';
    fetch(BASE + '/get_recent_posts', { credentials: 'same-origin' })
      .then(r => {
        // 2026-05-10 fix — was calling r.json() unconditionally; non-2xx
        // responses (401/403/5xx) with HTML or empty bodies left the UI
        // stuck in "Loading...". Now surface the HTTP error explicitly.
        if (r.status < 200 || r.status >= 300) {
          throw new Error('HTTP ' + r.status + ' from get_recent_posts');
        }
        return r.json();
      })
      .then(j => {
        const posts = (j.data && j.data.posts) || j.posts || [];
        if (!posts.length) { target.innerHTML = '<div style="color:#888;">No payroll journals found.</div>'; return; }
        const rows = posts.map(p => `
          <tr>
            <td><code>${p.entry_id}</code></td>
            <td><code>${p.source}</code></td>
            <td>${p.voucher_no}</td>
            <td>${p.date || ''}</td>
            <td style="text-align:right;">${p.total_dr}</td>
            <td style="text-align:right;">${p.total_cr}</td>
            <td>${p.lines_count}</td>
            <td>${p.created_by}</td>
            <td><button class="btn btn-xs btn-warning" onclick="prFillReversal('${p.entry_id}')">Reverse</button></td>
          </tr>`).join('');
        target.innerHTML = `
          <table class="pr-recent-table">
            <thead><tr>
              <th>Entry ID</th><th>Source</th><th>Voucher</th><th>Date</th>
              <th>Dr</th><th>Cr</th><th>Lines</th><th>By</th><th></th>
            </tr></thead>
            <tbody>${rows}</tbody>
          </table>`;
      })
      .catch(e => { target.innerHTML = '<div class="pr-result-err">Failed to load: ' + String(e) + '</div>'; });
  };

  window.prFillReversal = function(entryId) {
    document.querySelector('#pr-form-reversal [name=original_entry_id]').value = entryId;
    prShowTab('reversal');
    document.querySelector('#pr-form-reversal [name=reason]').focus();
  };
})();
</script>
