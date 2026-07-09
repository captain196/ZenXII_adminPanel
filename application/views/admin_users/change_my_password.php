<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($page_title ?? 'Set New Password') ?> · Zenxii</title>
<link rel="icon" type="image/png" href="<?= base_url('Designs/favicon.png?v=2') ?>">
<link rel="apple-touch-icon" href="<?= base_url('Designs/favicon.png?v=2') ?>">
<style>
  :root {
    --bg:#0f172a; --panel:#1e293b; --txt:#e2e8f0; --muted:#94a3b8;
    --accent:#38bdf8; --accent-strong:#0ea5e9; --error:#f87171; --ok:#34d399;
    --border:#334155;
  }
  *{box-sizing:border-box}
  body{margin:0;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;background:var(--bg);color:var(--txt);min-height:100vh;display:grid;place-items:center;padding:24px}
  .card{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:32px;width:100%;max-width:440px;box-shadow:0 24px 60px -20px rgba(0,0,0,.55)}
  h1{font-size:22px;margin:0 0 4px;font-weight:600}
  .sub{color:var(--muted);font-size:14px;margin:0 0 20px}
  .notice{background:rgba(248,113,113,.08);border:1px solid rgba(248,113,113,.3);color:#fecaca;padding:12px 14px;border-radius:8px;font-size:13.5px;line-height:1.45;margin-bottom:18px}
  label{display:block;font-size:13px;margin:14px 0 6px;color:var(--muted)}
  input[type=password]{width:100%;padding:12px 14px;background:#0b1220;border:1px solid var(--border);border-radius:8px;color:var(--txt);font-size:15px;outline:none}
  input[type=password]:focus{border-color:var(--accent)}
  .rules{font-size:12px;color:var(--muted);margin-top:6px;line-height:1.5}
  button{margin-top:22px;width:100%;background:var(--accent-strong);color:#001722;border:none;padding:12px 16px;border-radius:8px;font-size:15px;font-weight:600;cursor:pointer}
  button:hover{background:var(--accent)}
  button:disabled{opacity:.55;cursor:not-allowed}
  .msg{margin-top:14px;font-size:13.5px;min-height:18px}
  .msg.error{color:var(--error)}
  .msg.ok{color:var(--ok)}
  .who{color:var(--muted);font-size:12.5px;margin-top:10px}
  .pw-wrap{position:relative}
  .pw-wrap input{padding-right:46px}
  .pw-toggle{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:auto;margin:0;padding:8px;background:none;border:none;border-radius:6px;color:var(--muted);cursor:pointer;display:grid;place-items:center}
  .pw-toggle:hover{background:rgba(148,163,184,.12);color:var(--accent)}
  .pw-toggle svg{width:20px;height:20px;display:block}
  .pw-toggle .eye-off{display:none}
  .pw-toggle.on .eye{display:none}
  .pw-toggle.on .eye-off{display:block}
</style>
</head>
<body>
  <div class="card">
    <h1>Set a new password</h1>
    <p class="sub">Before you can continue, please choose a new password.</p>

    <?php if (!empty($must_change)): ?>
      <div class="notice">
        Your password was reset by an administrator. You're required to set a
        new one before accessing the rest of the panel.
      </div>
    <?php endif; ?>

    <form id="cpwForm" autocomplete="off">
      <?php if (empty($must_change)): ?>
      <label for="current_password">Current password</label>
      <div class="pw-wrap">
        <input id="current_password" name="current_password" type="password" required autocomplete="current-password">
        <button type="button" class="pw-toggle" data-target="current_password" aria-label="Show password" title="Show password">
          <svg class="eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3.5 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>
        </button>
      </div>
      <?php endif; ?>

      <label for="new_password">New password</label>
      <div class="pw-wrap">
        <input id="new_password" name="new_password" type="password" minlength="8" maxlength="72" required autofocus autocomplete="new-password">
        <button type="button" class="pw-toggle" data-target="new_password" aria-label="Show password" title="Show password">
          <svg class="eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3.5 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>
        </button>
      </div>

      <label for="confirm_password">Confirm new password</label>
      <div class="pw-wrap">
        <input id="confirm_password" name="confirm_password" type="password" minlength="8" maxlength="72" required autocomplete="new-password">
        <button type="button" class="pw-toggle" data-target="confirm_password" aria-label="Show password" title="Show password">
          <svg class="eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3.5 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>
        </button>
      </div>

      <div class="rules">8–72 characters. Must include an uppercase letter, a lowercase letter, and a digit.</div>

      <button type="submit" id="submitBtn">Save new password</button>
      <div class="msg" id="msg"></div>
      <div class="who">Signed in as <?= htmlspecialchars((string) ($admin_name ?: '')) ?> (<?= htmlspecialchars((string) $admin_id) ?>)</div>
    </form>
  </div>

<script>
(function(){
  const form    = document.getElementById('cpwForm');
  const newPw   = document.getElementById('new_password');
  const confPw  = document.getElementById('confirm_password');
  const msg     = document.getElementById('msg');
  const btn     = document.getElementById('submitBtn');
  const CSRF_NAME = <?= json_encode((string) ($csrf_name ?? 'csrf_token')) ?>;
  const CSRF_HASH = <?= json_encode((string) ($csrf_hash ?? '')) ?>;

  // Show/hide password toggles.
  document.querySelectorAll('.pw-toggle').forEach((tog) => {
    tog.addEventListener('click', () => {
      const input = document.getElementById(tog.dataset.target);
      if (!input) return;
      const show = input.type === 'password';
      input.type = show ? 'text' : 'password';
      tog.classList.toggle('on', show);
      const lbl = show ? 'Hide password' : 'Show password';
      tog.setAttribute('aria-label', lbl);
      tog.setAttribute('title', lbl);
    });
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.textContent = '';
    msg.className   = 'msg';

    if (newPw.value !== confPw.value) {
      msg.textContent = 'Passwords do not match.';
      msg.className   = 'msg error';
      return;
    }

    btn.disabled = true;
    btn.textContent = 'Saving…';

    try {
      const body = new URLSearchParams();
      body.set('new_password',     newPw.value);
      body.set('confirm_password', confPw.value);
      var curPw = document.getElementById('current_password');
      if (curPw) body.set('current_password', curPw.value);
      body.set(CSRF_NAME,          CSRF_HASH);

      const res = await fetch('<?= site_url('admin_users/change_my_password') ?>', {
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
        msg.textContent = json.message || 'Password updated. Redirecting…';
        msg.className   = 'msg ok';
        const target    = json.redirect || '<?= base_url('admin/index') ?>';
        setTimeout(() => { window.location.href = target; }, 700);
        return;
      }

      msg.textContent = (json.message || json.error || 'Failed to update password.');
      msg.className   = 'msg error';
    } catch (err) {
      msg.textContent = 'Network error: ' + err.message;
      msg.className   = 'msg error';
    } finally {
      btn.disabled = false;
      btn.textContent = 'Save new password';
    }
  });
})();
</script>
</body>
</html>
