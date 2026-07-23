<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($page_title ?? 'Set New Password') ?> · ZenXii</title>
<link rel="icon" type="image/png" href="<?= base_url('Designs/favicon.png?v=2') ?>">
<link rel="apple-touch-icon" href="<?= base_url('Designs/favicon.png?v=2') ?>">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
<style>
/* Burnt Clay system palette — day + night, synced to the login page's theme pref. */
:root {
  --brand:#BC5A3C; --brand2:#9E4830; --brand3:#DD8464;
  --brand-dim:rgba(188,90,60,.12); --brand-ring:rgba(188,90,60,.30);
  --sans:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
  --mono:'JetBrains Mono',ui-monospace,monospace;
  --ease:.22s cubic-bezier(.4,0,.2,1);
  --ok:#16a34a; --error:#dc2626; --warn:#d97706;
  /* light defaults (overridden by [data-theme]) */
  --bg:#F7F2ED; --bg2:#ECDCCF; --card:rgba(255,255,255,.94);
  --border:rgba(188,90,60,.16); --border2:rgba(188,90,60,.30);
  --txt:#22160F; --txt2:#33261F; --muted:#9E8578;
  --input-bg:rgba(250,245,240,.85); --input-foc:#ffffff;
  --card-sh:0 24px 70px -24px rgba(90,50,25,.35), 0 2px 8px rgba(0,0,0,.05);
  --notice-bg:rgba(217,119,6,.10); --notice-brd:rgba(217,119,6,.30); --notice-txt:#8a5a12;
}
[data-theme="dark"] {
  --bg:#17100C; --bg2:#0E0906; --card:rgba(32,23,17,.94);
  --border:rgba(212,114,92,.16); --border2:rgba(212,114,92,.28);
  --txt:#F4E9E2; --txt2:#E6D6CB; --muted:#A89A90;
  --input-bg:rgba(23,16,12,.7); --input-foc:rgba(39,26,20,.95);
  --card-sh:0 32px 80px rgba(0,0,0,.6), 0 0 0 1px rgba(212,114,92,.06);
  --notice-bg:rgba(217,119,6,.14); --notice-brd:rgba(217,119,6,.32); --notice-txt:#fbbf24;
}
*{box-sizing:border-box}
body{margin:0;font-family:var(--sans);background:
  radial-gradient(1200px 600px at 15% -10%, var(--brand-dim), transparent 60%),
  radial-gradient(900px 500px at 100% 110%, var(--brand-dim), transparent 55%),
  var(--bg);
  color:var(--txt);min-height:100vh;display:grid;place-items:center;padding:24px;
  transition:background var(--ease),color var(--ease)}

.card{background:var(--card);backdrop-filter:blur(8px);border:1px solid var(--border2);
  border-radius:18px;padding:34px 32px 30px;width:100%;max-width:452px;box-shadow:var(--card-sh)}

.brandrow{display:flex;align-items:center;gap:12px;margin-bottom:20px}
.brandmark{width:46px;height:46px;border-radius:13px;flex-shrink:0;display:grid;place-items:center;
  background:linear-gradient(140deg,var(--brand3),var(--brand),var(--brand2));color:#fff;font-size:19px;
  box-shadow:0 8px 20px -6px var(--brand-ring)}
.brandrow .bt{font-size:13px;font-weight:800;letter-spacing:.3px;color:var(--txt)}
.brandrow .bs{font-size:11.5px;color:var(--muted);margin-top:1px}

h1{font-size:22px;margin:2px 0 4px;font-weight:800;letter-spacing:-.02em}
.sub{color:var(--muted);font-size:13.5px;margin:0 0 18px;line-height:1.5}

.notice{background:var(--notice-bg);border:1px solid var(--notice-brd);color:var(--notice-txt);
  padding:12px 14px;border-radius:10px;font-size:13px;line-height:1.5;margin-bottom:18px;
  display:flex;gap:10px;align-items:flex-start}
.notice i{margin-top:2px;font-size:14px;flex-shrink:0}

label{display:block;font-size:12.5px;font-weight:600;margin:15px 0 7px;color:var(--txt2)}
.pw-wrap{position:relative}
input[type=password],input[type=text]{width:100%;padding:12px 46px 12px 14px;background:var(--input-bg);
  border:1.5px solid var(--border);border-radius:10px;color:var(--txt);font-size:15px;outline:none;
  font-family:var(--sans);transition:border-color var(--ease),background var(--ease)}
input:focus{border-color:var(--brand);background:var(--input-foc);box-shadow:0 0 0 3px var(--brand-dim)}
.pw-toggle{position:absolute;right:6px;top:50%;transform:translateY(-50%);width:auto;margin:0;padding:8px;
  background:none;border:none;border-radius:8px;color:var(--muted);cursor:pointer;display:grid;place-items:center}
.pw-toggle:hover{background:var(--brand-dim);color:var(--brand)}
.pw-toggle svg{width:20px;height:20px;display:block}
.pw-toggle .eye-off{display:none}
.pw-toggle.on .eye{display:none}
.pw-toggle.on .eye-off{display:block}

/* strength meter */
.meter{height:5px;border-radius:4px;background:var(--bg2);margin:10px 0 4px;overflow:hidden}
.meter i{display:block;height:100%;width:0;border-radius:4px;transition:width var(--ease),background var(--ease)}
.meter-note{font-size:11.5px;color:var(--muted);min-height:15px}

.rules{font-size:11.5px;color:var(--muted);margin-top:8px;line-height:1.6;display:flex;flex-wrap:wrap;gap:4px 14px}
.rules .rk{display:inline-flex;align-items:center;gap:5px;transition:color var(--ease)}
.rules .rk i{font-size:10px}
.rules .rk.ok{color:var(--ok)}

button.submit{margin-top:22px;width:100%;background:linear-gradient(135deg,var(--brand3),var(--brand) 55%,var(--brand2));
  color:#fff;border:none;padding:13px 16px;border-radius:11px;font-size:15px;font-weight:700;cursor:pointer;
  font-family:var(--sans);box-shadow:0 10px 24px -10px var(--brand-ring);transition:filter var(--ease),transform var(--ease)}
button.submit:hover{filter:brightness(1.05)}
button.submit:active{transform:translateY(1px)}
button.submit:disabled{opacity:.55;cursor:not-allowed;filter:none}

.msg{margin-top:14px;font-size:13px;min-height:18px;line-height:1.45}
.msg.error{color:var(--error)}
.msg.ok{color:var(--ok)}
.who{color:var(--muted);font-size:12px;margin-top:14px;display:flex;align-items:center;gap:6px}
.who i{font-size:11px}

.theme-btn{position:fixed;top:18px;right:18px;width:40px;height:40px;border-radius:11px;background:var(--card);
  border:1px solid var(--border2);color:var(--muted);cursor:pointer;font-size:14px;display:grid;place-items:center;
  transition:all var(--ease);z-index:10}
.theme-btn:hover{border-color:var(--brand3);color:var(--brand)}
[data-theme="light"] .ico-moon{display:none}
[data-theme="dark"]  .ico-sun{display:none}
</style>
<script>
/* Apply saved/time theme before paint — shares the login page's preference keys. */
(function(){try{var k=localStorage.getItem('graderadmin_theme'),m=localStorage.getItem('graderadmin_manual')==='1',h=new Date().getHours();document.documentElement.setAttribute('data-theme',(m&&k)?k:((h>=6&&h<18)?'light':'dark'));}catch(e){document.documentElement.setAttribute('data-theme','light');}})();
</script>
</head>
<body>
  <button class="theme-btn" id="themeBtn" title="Toggle theme" aria-label="Toggle theme">
    <i class="fas fa-sun ico-sun"></i><i class="fas fa-moon ico-moon"></i>
  </button>

  <div class="card">
    <div class="brandrow">
      <div class="brandmark"><i class="fas fa-shield-halved"></i></div>
      <div>
        <div class="bt">ZENXII</div>
        <div class="bs">Account security</div>
      </div>
    </div>

    <h1>Set a new password</h1>
    <p class="sub">Before you can continue, please choose a new password for your account.</p>

    <?php if (!empty($must_change)): ?>
      <div class="notice">
        <i class="fas fa-triangle-exclamation"></i>
        <span>Your password was reset by an administrator. You're required to set a new one before accessing the rest of the panel.</span>
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

      <div class="meter"><i id="pwMeter"></i></div>
      <div class="meter-note" id="pwMeterNote"></div>

      <label for="confirm_password">Confirm new password</label>
      <div class="pw-wrap">
        <input id="confirm_password" name="confirm_password" type="password" minlength="8" maxlength="72" required autocomplete="new-password">
        <button type="button" class="pw-toggle" data-target="confirm_password" aria-label="Show password" title="Show password">
          <svg class="eye" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7Z"/><circle cx="12" cy="12" r="3"/></svg>
          <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c6.5 0 10 7 10 7a13.16 13.16 0 0 1-1.67 2.68"/><path d="M6.61 6.61A13.526 13.526 0 0 0 2 12s3.5 7 10 7a9.74 9.74 0 0 0 5.39-1.61"/><line x1="2" y1="2" x2="22" y2="22"/></svg>
        </button>
      </div>

      <div class="rules" id="rules">
        <span class="rk" data-rk="len"><i class="fas fa-circle"></i>8–72 characters</span>
        <span class="rk" data-rk="upper"><i class="fas fa-circle"></i>Uppercase</span>
        <span class="rk" data-rk="lower"><i class="fas fa-circle"></i>Lowercase</span>
        <span class="rk" data-rk="digit"><i class="fas fa-circle"></i>Digit</span>
      </div>

      <button type="submit" class="submit" id="submitBtn">Save new password</button>
      <div class="msg" id="msg"></div>
      <div class="who"><i class="fas fa-user"></i>Signed in as <?= htmlspecialchars((string) ($admin_name ?: '')) ?> (<?= htmlspecialchars((string) $admin_id) ?>)</div>
    </form>
  </div>

<script>
(function(){
  const form    = document.getElementById('cpwForm');
  const newPw   = document.getElementById('new_password');
  const confPw  = document.getElementById('confirm_password');
  const msg     = document.getElementById('msg');
  const btn     = document.getElementById('submitBtn');
  const meter   = document.getElementById('pwMeter');
  const meterN  = document.getElementById('pwMeterNote');
  const CSRF_NAME = <?= json_encode((string) ($csrf_name ?? 'csrf_token')) ?>;
  const CSRF_HASH = <?= json_encode((string) ($csrf_hash ?? '')) ?>;

  /* Theme toggle — writes the same keys the login page reads, so they stay in sync. */
  (function(){var b=document.getElementById('themeBtn'),h=document.documentElement;if(!b)return;
    b.addEventListener('click',function(){var n=h.getAttribute('data-theme')==='dark'?'light':'dark';
      h.setAttribute('data-theme',n);try{localStorage.setItem('graderadmin_theme',n);localStorage.setItem('graderadmin_manual','1');}catch(e){}});})();

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

  // Live rule checklist + strength meter.
  const rk = {};
  document.querySelectorAll('#rules .rk').forEach(el => rk[el.dataset.rk] = el);
  function scorePw(pw){
    const c = {
      len:   pw.length >= 8 && pw.length <= 72,
      upper: /[A-Z]/.test(pw),
      lower: /[a-z]/.test(pw),
      digit: /[0-9]/.test(pw),
    };
    Object.keys(rk).forEach(k => {
      rk[k].classList.toggle('ok', c[k]);
      rk[k].querySelector('i').className = c[k] ? 'fas fa-circle-check' : 'fas fa-circle';
    });
    let s = (c.len?1:0)+(c.upper?1:0)+(c.lower?1:0)+(c.digit?1:0);
    if (pw.length >= 12) s += 1;
    if (/[^A-Za-z0-9]/.test(pw)) s += 1;
    return { s, valid: c.len && c.upper && c.lower && c.digit };
  }
  function paintMeter(pw){
    if (!pw){ meter.style.width='0'; meterN.textContent=''; return; }
    const { s } = scorePw(pw);
    const pct = Math.min(100, Math.round((s/6)*100));
    const col = s <= 2 ? 'var(--error)' : s <= 4 ? 'var(--warn)' : 'var(--ok)';
    const lbl = s <= 2 ? 'Weak' : s <= 4 ? 'Fair' : 'Strong';
    meter.style.width = pct + '%'; meter.style.background = col;
    meterN.textContent = 'Strength: ' + lbl;
    meterN.style.color = col;
  }
  newPw.addEventListener('input', () => paintMeter(newPw.value));

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.textContent = '';
    msg.className   = 'msg';

    const chk = scorePw(newPw.value);
    if (!chk.valid) {
      msg.textContent = 'Password must be 8–72 chars with an uppercase, lowercase, and a digit.';
      msg.className   = 'msg error';
      newPw.focus();
      return;
    }
    if (newPw.value !== confPw.value) {
      msg.textContent = 'Passwords do not match.';
      msg.className   = 'msg error';
      confPw.focus();
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
