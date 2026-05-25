<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($page_title ?? 'Set New Password') ?> · Zenxii</title>
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
      <label for="new_password">New password</label>
      <input id="new_password" name="new_password" type="password" minlength="8" maxlength="72" required autofocus autocomplete="new-password">

      <label for="confirm_password">Confirm new password</label>
      <input id="confirm_password" name="confirm_password" type="password" minlength="8" maxlength="72" required autocomplete="new-password">

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
