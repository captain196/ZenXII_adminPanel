<?php
/**
 * Staff Separation modal — shared partial.
 *
 * Wires the fields Staff::set_status() already accepts on an Inactive
 * transition (separation_type, exit_date, last_working_date, reason) but
 * which, until now, no admin-panel UI actually submitted — the deactivate
 * control was a bare window.prompt() capturing only free-text reason, so
 * separationType always defaulted to 'Other' in practice.
 *
 * Usage:
 *   $staff_sep_endpoint = base_url('staff/set_status/'); // trailing slash, id appended by JS
 *   $this->load->view('partials/staff_separation_modal', [...]);
 *
 * The trigger button in the parent view must carry:
 *   class="staff-status-toggle" data-target-status="Inactive"
 *   data-user-id="..." data-staff-name="..."
 * (Reactivate — data-target-status="Active" — is NOT handled by this modal;
 * the parent view's existing lightweight confirm flow covers it unchanged,
 * since Active has no separation-specific fields to collect.)
 */
$endpoint = $staff_sep_endpoint ?? base_url('staff/set_status/');
$csrfName = $this->security->get_csrf_token_name();
$csrfHash = $this->security->get_csrf_hash();
?>
<style>
  .ssm-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:9999;padding:20px}
  .ssm-backdrop.open{display:flex}
  .ssm-modal{background:#fff;width:100%;max-width:460px;border-radius:12px;box-shadow:0 24px 60px -20px rgba(0,0,0,.4);overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
  .ssm-head{padding:18px 22px 4px}
  .ssm-head h3{margin:0 0 4px;font-size:17px;color:#0f172a;font-weight:600}
  .ssm-head .who{font-size:13px;color:#64748b}
  .ssm-body{padding:14px 22px 6px;max-height:60vh;overflow-y:auto}
  .ssm-body label{display:block;font-size:12.5px;color:#475569;margin:10px 0 6px;font-weight:500}
  .ssm-body select,.ssm-body input[type=date],.ssm-body textarea{width:100%;padding:9px 11px;border:1px solid #cbd5e1;border-radius:7px;font-size:13.5px;outline:none;font-family:inherit}
  .ssm-body select:focus,.ssm-body input:focus,.ssm-body textarea:focus{border-color:#dc2626}
  .ssm-body textarea{resize:vertical;min-height:56px}
  .ssm-row2{display:grid;grid-template-columns:1fr 1fr;gap:10px}
  .ssm-hint{font-size:11px;color:#94a3b8;margin-top:4px}
  .ssm-msg{font-size:13px;margin-top:10px;min-height:18px;color:#dc2626}
  .ssm-foot{padding:12px 22px 18px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #e2e8f0;margin-top:8px}
  .ssm-foot button{padding:9px 16px;border-radius:7px;font-size:13.5px;font-weight:500;border:none;cursor:pointer}
  .ssm-cancel{background:#e2e8f0;color:#334155}
  .ssm-submit{background:#dc2626;color:#fff}
  .ssm-submit:hover{background:#b91c1c}
  .ssm-submit:disabled{opacity:.55;cursor:not-allowed}
</style>

<div class="ssm-backdrop" id="ssmBackdrop">
  <div class="ssm-modal" role="dialog" aria-modal="true" aria-labelledby="ssmTitle">
    <div class="ssm-head">
      <h3 id="ssmTitle">Deactivate Staff</h3>
      <div class="who" id="ssmWho"></div>
    </div>

    <div class="ssm-body">
      <label for="ssmType">Separation type</label>
      <select id="ssmType">
        <option value="Resignation">Resignation</option>
        <option value="Termination">Termination</option>
        <option value="Retirement">Retirement</option>
        <option value="Contract Expiry">Contract Expiry</option>
        <option value="Other" selected>Other</option>
      </select>

      <div class="ssm-row2">
        <div>
          <label for="ssmLastWorking">Last working day</label>
          <input type="date" id="ssmLastWorking">
        </div>
        <div>
          <label for="ssmExitDate">Exit date</label>
          <input type="date" id="ssmExitDate">
        </div>
      </div>
      <div class="ssm-hint">Both default to today if left blank.</div>

      <label for="ssmReason">Reason (optional)</label>
      <textarea id="ssmReason" placeholder="Optional note — visible only to staff with HR access"></textarea>

      <div class="ssm-msg" id="ssmMsg"></div>
    </div>

    <div class="ssm-foot">
      <button type="button" class="ssm-cancel" id="ssmCancelBtn">Cancel</button>
      <button type="button" class="ssm-submit" id="ssmSubmitBtn">Deactivate</button>
    </div>
  </div>
</div>

<script>
(function(){
  const ENDPOINT  = <?= json_encode($endpoint) ?>;
  const CSRF_NAME = <?= json_encode($csrfName) ?>;
  const CSRF_HASH = <?= json_encode($csrfHash) ?>;

  const backdrop  = document.getElementById('ssmBackdrop');
  const whoEl     = document.getElementById('ssmWho');
  const typeSel   = document.getElementById('ssmType');
  const lastWork  = document.getElementById('ssmLastWorking');
  const exitDate  = document.getElementById('ssmExitDate');
  const reasonTa  = document.getElementById('ssmReason');
  const msg       = document.getElementById('ssmMsg');
  const submit    = document.getElementById('ssmSubmitBtn');
  const cancel    = document.getElementById('ssmCancelBtn');

  let currentUserId = null;
  let onDone = null; // callback(res) invoked after a successful submit

  function todayISO() {
    const d = new Date();
    return d.getFullYear() + '-' + String(d.getMonth()+1).padStart(2,'0') + '-' + String(d.getDate()).padStart(2,'0');
  }

  function open(userId, userName, doneCb) {
    currentUserId = userId;
    onDone = doneCb || null;
    whoEl.textContent = userName + '  ·  ' + userId;
    typeSel.value = 'Other';
    lastWork.value = todayISO();
    exitDate.value = todayISO();
    reasonTa.value = '';
    msg.textContent = '';
    submit.disabled = false;
    submit.textContent = 'Deactivate';
    backdrop.classList.add('open');
  }
  function close() {
    backdrop.classList.remove('open');
    currentUserId = null;
    onDone = null;
  }
  cancel.addEventListener('click', close);
  backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && backdrop.classList.contains('open')) close();
  });

  submit.addEventListener('click', async () => {
    // Caught here as well as on the server so the operator is told before a
    // round-trip. The server refuses it regardless — this is the courtesy,
    // not the guarantee.
    if (lastWork.value && exitDate.value && lastWork.value > exitDate.value) {
      msg.textContent = 'The last working day cannot be after the exit date.';
      return;
    }

    submit.disabled = true;
    submit.textContent = 'Saving…';
    msg.textContent = '';

    const body = new URLSearchParams();
    body.set('status', 'Inactive');
    body.set('reason', reasonTa.value);
    body.set('separation_type', typeSel.value);
    body.set('last_working_date', lastWork.value);
    body.set('exit_date', exitDate.value);
    body.set(CSRF_NAME, CSRF_HASH);

    try {
      const res = await fetch(ENDPOINT + encodeURIComponent(currentUserId), {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        credentials: 'same-origin',
        body: body.toString(),
      });
      const json = await res.json().catch(() => ({ status: 'error', message: 'Server returned non-JSON.' }));
      if (json && json.status === 'success') {
        const cb = onDone;
        close();
        if (cb) cb(json);
        return;
      }
      msg.textContent = (json && json.message) ? json.message : 'Deactivation failed.';
      submit.disabled = false;
      submit.textContent = 'Deactivate';
    } catch (e) {
      msg.textContent = 'Network error: ' + (e && e.message ? e.message : 'unknown');
      submit.disabled = false;
      submit.textContent = 'Deactivate';
    }
  });

  // Exposed so the parent view's existing click-delegation can open this
  // modal instead of window.prompt() for Inactive transitions.
  window.__staffSeparationModal = { open };
})();
</script>
