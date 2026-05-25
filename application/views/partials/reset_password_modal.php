<?php
/**
 * Reset Password modal — shared partial.
 *
 * Usage:
 *   $reset_pw_endpoint = base_url('staff/reset_password');  // or 'sis/reset_password'
 *   $reset_pw_label    = 'Staff';                            // or 'Student'
 *   $this->load->view('partials/reset_password_modal', [...]);
 *
 * The trigger button in the parent view must carry:
 *   class="js-reset-pw-btn" data-user-id="..." data-user-name="..."
 */
$endpoint = $reset_pw_endpoint ?? base_url('staff/reset_password');
$label    = $reset_pw_label    ?? 'User';
$csrfName = $this->security->get_csrf_token_name();
$csrfHash = $this->security->get_csrf_hash();
?>

<style>
  .rpw-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.55);display:none;align-items:center;justify-content:center;z-index:9999;padding:20px}
  .rpw-backdrop.open{display:flex}
  .rpw-modal{background:#fff;width:100%;max-width:440px;border-radius:12px;box-shadow:0 24px 60px -20px rgba(0,0,0,.4);overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}
  .rpw-head{padding:18px 22px 4px}
  .rpw-head h3{margin:0 0 4px;font-size:17px;color:#0f172a;font-weight:600}
  .rpw-head .who{font-size:13px;color:#64748b}
  .rpw-body{padding:14px 22px 6px}
  .rpw-body label{display:block;font-size:12.5px;color:#475569;margin:10px 0 6px}
  .rpw-body input[type=password],.rpw-body input[type=text]{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:7px;font-size:14px;outline:none}
  .rpw-body input:focus{border-color:#38bdf8}
  .rpw-row{display:flex;gap:8px;align-items:stretch}
  .rpw-row input{flex:1}
  .rpw-row .rpw-gen{flex:0 0 auto;background:#f1f5f9;border:1px solid #cbd5e1;color:#334155;border-radius:7px;padding:0 12px;font-size:12.5px;cursor:pointer}
  .rpw-row .rpw-gen:hover{background:#e2e8f0}
  .rpw-rules{font-size:11.5px;color:#64748b;margin-top:6px;line-height:1.5}
  .rpw-msg{font-size:13px;margin-top:10px;min-height:18px}
  .rpw-msg.err{color:#dc2626}
  .rpw-msg.ok{color:#16a34a}
  .rpw-foot{padding:12px 22px 18px;display:flex;justify-content:flex-end;gap:8px;border-top:1px solid #e2e8f0;margin-top:8px}
  .rpw-foot button{padding:9px 16px;border-radius:7px;font-size:13.5px;font-weight:500;border:none;cursor:pointer}
  .rpw-cancel{background:#e2e8f0;color:#334155}
  .rpw-submit{background:#0ea5e9;color:#fff}
  .rpw-submit:hover{background:#0284c7}
  .rpw-submit:disabled{opacity:.55;cursor:not-allowed}
  .rpw-success{padding:16px 22px;background:#ecfdf5;border-top:1px solid #d1fae5}
  .rpw-success-pw{display:flex;gap:8px;align-items:center;background:#fff;border:1px dashed #34d399;border-radius:7px;padding:10px 12px;font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-size:14.5px;color:#065f46}
  .rpw-success-pw .pw-text{flex:1;user-select:all;word-break:break-all}
  .rpw-success-pw .copy-btn{background:#34d399;color:#fff;border:none;border-radius:5px;padding:6px 10px;font-size:12px;cursor:pointer}
  .rpw-success-pw .copy-btn:hover{background:#10b981}
  .rpw-success-note{font-size:12px;color:#065f46;margin-top:10px;line-height:1.5}
</style>

<div class="rpw-backdrop" id="rpwBackdrop">
  <div class="rpw-modal" role="dialog" aria-modal="true" aria-labelledby="rpwTitle">
    <div class="rpw-head">
      <h3 id="rpwTitle">Reset <?= htmlspecialchars($label) ?> Password</h3>
      <div class="who" id="rpwWho"></div>
    </div>

    <div class="rpw-body" id="rpwFormPane">
      <label for="rpwNewPw">New password</label>
      <div class="rpw-row">
        <input type="password" id="rpwNewPw" minlength="8" maxlength="72" autocomplete="off" spellcheck="false">
        <button type="button" class="rpw-gen" id="rpwGenBtn" title="Generate strong password">Generate</button>
      </div>
      <div class="rpw-rules">8–72 chars. Must include uppercase, lowercase, and a digit.</div>
      <div class="rpw-msg" id="rpwMsg"></div>
    </div>

    <div class="rpw-success" id="rpwSuccess" style="display:none">
      <div style="font-size:13px;color:#065f46;font-weight:600;margin-bottom:8px">
        ✓ Password reset successfully.
      </div>
      <div class="rpw-success-pw">
        <span class="pw-text" id="rpwShownPw"></span>
        <button type="button" class="copy-btn" id="rpwCopyBtn">Copy</button>
      </div>
      <div class="rpw-success-note">
        Share this with the user securely (phone, in person). They'll be required
        to change it on their next login.
      </div>
    </div>

    <div class="rpw-foot">
      <button type="button" class="rpw-cancel" id="rpwCancelBtn">Cancel</button>
      <button type="button" class="rpw-submit" id="rpwSubmitBtn">Reset password</button>
    </div>
  </div>
</div>

<script>
(function(){
  const ENDPOINT  = <?= json_encode($endpoint) ?>;
  const CSRF_NAME = <?= json_encode($csrfName) ?>;
  const CSRF_HASH = <?= json_encode($csrfHash) ?>;

  const backdrop = document.getElementById('rpwBackdrop');
  const whoEl    = document.getElementById('rpwWho');
  const newPw    = document.getElementById('rpwNewPw');
  const genBtn   = document.getElementById('rpwGenBtn');
  const msg      = document.getElementById('rpwMsg');
  const submit   = document.getElementById('rpwSubmitBtn');
  const cancel   = document.getElementById('rpwCancelBtn');
  const formPane = document.getElementById('rpwFormPane');
  const success  = document.getElementById('rpwSuccess');
  const shownPw  = document.getElementById('rpwShownPw');
  const copyBtn  = document.getElementById('rpwCopyBtn');

  let currentUserId = null;

  function open(userId, userName) {
    currentUserId = userId;
    whoEl.textContent = userName + '  ·  ' + userId;
    newPw.value = '';
    msg.textContent = '';
    msg.className = 'rpw-msg';
    formPane.style.display = '';
    success.style.display = 'none';
    submit.disabled = false;
    submit.textContent = 'Reset password';
    backdrop.classList.add('open');
    setTimeout(() => newPw.focus(), 30);
  }
  function close() {
    backdrop.classList.remove('open');
    currentUserId = null;
  }
  cancel.addEventListener('click', close);
  backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && backdrop.classList.contains('open')) close();
  });

  // Generate strong password meeting policy.
  genBtn.addEventListener('click', () => {
    const lower = 'abcdefghijkmnpqrstuvwxyz';
    const upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
    const digit = '23456789';
    const sym   = '@#$%&*';
    const all   = lower + upper + digit + sym;
    let pw = lower[Math.floor(Math.random()*lower.length)]
           + upper[Math.floor(Math.random()*upper.length)]
           + digit[Math.floor(Math.random()*digit.length)];
    for (let i = 3; i < 12; i++) pw += all[Math.floor(Math.random()*all.length)];
    // Shuffle
    pw = pw.split('').sort(() => Math.random() - 0.5).join('');
    newPw.type = 'text';
    newPw.value = pw;
  });

  function validate(pw) {
    if (pw.length < 8 || pw.length > 72) return 'Password must be 8–72 characters.';
    if (!/[A-Z]/.test(pw))               return 'Must include an uppercase letter.';
    if (!/[a-z]/.test(pw))               return 'Must include a lowercase letter.';
    if (!/[0-9]/.test(pw))               return 'Must include a digit.';
    return null;
  }

  submit.addEventListener('click', async () => {
    const pw = newPw.value;
    const err = validate(pw);
    if (err) {
      msg.textContent = err;
      msg.className   = 'rpw-msg err';
      return;
    }
    submit.disabled = true;
    submit.textContent = 'Saving…';
    msg.textContent = '';

    const body = new URLSearchParams();
    body.set('user_id', currentUserId);
    body.set('new_password', pw);
    body.set(CSRF_NAME, CSRF_HASH);

    try {
      const res = await fetch(ENDPOINT, {
        method: 'POST',
        headers: {
          'X-Requested-With': 'XMLHttpRequest',
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        credentials: 'same-origin',
        body: body.toString(),
      });
      const json = await res.json().catch(() => ({}));
      if (res.ok && (json.status === 'success' || json.success === true)) {
        formPane.style.display = 'none';
        shownPw.textContent = pw;
        success.style.display = '';
        submit.style.display = 'none';
        cancel.textContent = 'Done';
        return;
      }
      msg.textContent = json.message || json.error || 'Reset failed.';
      msg.className   = 'rpw-msg err';
      submit.disabled = false;
      submit.textContent = 'Reset password';
    } catch (e) {
      msg.textContent = 'Network error: ' + e.message;
      msg.className   = 'rpw-msg err';
      submit.disabled = false;
      submit.textContent = 'Reset password';
    }
  });

  copyBtn.addEventListener('click', async () => {
    try {
      await navigator.clipboard.writeText(shownPw.textContent);
      copyBtn.textContent = 'Copied';
      setTimeout(() => { copyBtn.textContent = 'Copy'; }, 1500);
    } catch {
      const r = document.createRange();
      r.selectNode(shownPw);
      window.getSelection().removeAllRanges();
      window.getSelection().addRange(r);
    }
  });

  // Wire up trigger buttons (event delegation so it works for late-added rows).
  document.addEventListener('click', (e) => {
    const btn = e.target.closest && e.target.closest('.js-reset-pw-btn');
    if (!btn) return;
    e.preventDefault();
    const uid  = btn.getAttribute('data-user-id');
    const name = btn.getAttribute('data-user-name') || uid;
    if (uid) open(uid, name);
  });

  // Reset to password type after closing.
  backdrop.addEventListener('transitionend', () => {
    if (!backdrop.classList.contains('open')) newPw.type = 'password';
  });
  cancel.addEventListener('click', () => { newPw.type = 'password'; });
})();
</script>
