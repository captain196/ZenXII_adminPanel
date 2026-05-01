<?php defined('BASEPATH') or exit('No direct script access allowed');
$displayName = $profile['display_name'] ?? $school_name;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Online Admission — <?= htmlspecialchars($displayName) ?></title>
    <meta name="csrf-token" content="<?= $this->security->get_csrf_hash() ?>">
    <meta name="csrf-name" content="<?= $this->security->get_csrf_token_name() ?>">
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Syne:wght@600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
    <style>
        :root {
            --gold:#0f766e; --gold2:#0d6b63; --gold3:#14b8a6;
            --gold-dim:rgba(15,118,110,.10); --gold-ring:rgba(15,118,110,.22);
            --bg:#f0f7f5; --bg2:#ffffff; --bg3:#e6f4f1;
            --border:rgba(15,118,110,.15);
            --t1:#0c1e38; --t2:#1a5c56; --t3:#5a9e98;
            --r:12px;
            --font-d:'Syne',sans-serif; --font-b:'Plus Jakarta Sans',sans-serif; --font-m:'JetBrains Mono',monospace;
            --ease:.22s cubic-bezier(.4,0,.2,1);
        }
        * { box-sizing:border-box; margin:0; padding:0; }
        html { font-size:16px; }
        body { font-family:var(--font-b); background:var(--bg); color:var(--t1); min-height:100vh; }

        .of-wrap { max-width:780px; margin:0 auto; padding:32px 20px 48px; }

        /* ── School Header ── */
        .of-school {
            text-align:center; margin-bottom:28px; padding:24px;
            background:var(--bg2); border:1px solid var(--border);
            border-radius:var(--r); box-shadow:0 2px 16px rgba(0,0,0,.06);
        }
        .of-school img { height:60px; margin-bottom:12px; }
        .of-school h1 { font-size:20px; font-weight:800; color:var(--gold); font-family:var(--font-d); margin-bottom:4px; }
        .of-school p { font-size:13px; color:var(--t3); }

        /* ── Form Card ── */
        .of-card {
            background:var(--bg2); border:1px solid var(--border);
            border-radius:var(--r); padding:28px;
            box-shadow:0 2px 16px rgba(0,0,0,.06);
        }
        .of-card h2 {
            font-size:16px; font-weight:700; color:var(--t1); font-family:var(--font-d);
            margin-bottom:20px; padding-bottom:12px; border-bottom:2px solid var(--gold);
            display:flex; align-items:center; gap:10px;
        }
        .of-card h2 i { color:var(--gold); }

        .of-section {
            font-size:13px; font-weight:700; color:var(--gold); text-transform:uppercase;
            letter-spacing:.4px; margin:22px 0 12px; padding-top:14px;
            border-top:1px solid var(--border);
            display:flex; align-items:center; gap:8px;
        }
        .of-section i { font-size:14px; }

        .of-grid { display:grid; grid-template-columns:1fr 1fr; gap:0 16px; }
        .of-fg { display:flex; flex-direction:column; gap:4px; margin-bottom:14px; }
        .of-fg label { font-size:11px; font-weight:600; color:var(--t3); text-transform:uppercase; letter-spacing:.4px; }
        .of-fg .req { color:#ef4444; }
        .of-fg input, .of-fg select, .of-fg textarea {
            padding:10px 12px; border:1px solid var(--border); border-radius:8px;
            background:#fff; color:var(--t1); font-size:14px; font-family:var(--font-b);
            outline:none; transition:border-color var(--ease), box-shadow var(--ease);
        }
        .of-fg input:focus, .of-fg select:focus, .of-fg textarea:focus {
            border-color:var(--gold); box-shadow:0 0 0 3px var(--gold-ring);
        }
        .of-fg textarea { resize:vertical; min-height:60px; }
        .of-fg-full { grid-column:1/-1; }

        .of-submit {
            width:100%; padding:14px; background:var(--gold); color:#fff; border:none;
            border-radius:8px; font-size:14px; font-weight:700; cursor:pointer;
            font-family:var(--font-b); transition:background var(--ease);
            display:flex; align-items:center; justify-content:center; gap:8px;
            margin-top:8px;
        }
        .of-submit:hover { background:var(--gold2); }
        .of-submit:disabled { opacity:.6; cursor:not-allowed; }

        .of-alert { padding:12px 16px; border-radius:8px; font-size:13px; display:none; margin-bottom:16px; }
        .of-alert-success { background:#dcfce7; color:#166534; border:1px solid #bbf7d0; }
        .of-alert-error { background:#fee2e2; color:#991b1b; border:1px solid #fecaca; }

        .of-success { display:none; text-align:center; padding:48px 24px; }
        .of-success i { font-size:3.5rem; color:var(--gold); margin-bottom:16px; display:block; }
        .of-success h2 { font-size:18px; font-weight:700; color:var(--t1); margin-bottom:8px; font-family:var(--font-d); }
        .of-success p { color:var(--t3); font-size:14px; margin-bottom:4px; }
        .of-success strong { color:var(--gold); font-family:var(--font-m); }
        .of-success button {
            margin-top:24px; padding:12px 28px; background:var(--gold); color:#fff;
            border:none; border-radius:8px; cursor:pointer; font-size:13px;
            font-weight:600; font-family:var(--font-b);
        }
        .of-success button:hover { background:var(--gold2); }

        .of-footer { text-align:center; margin-top:24px; font-size:11px; color:var(--t3); }

        @media(max-width:600px) { .of-grid { grid-template-columns:1fr; } }
    </style>
</head>
<body>
<div class="of-wrap">

    <div class="of-school">
        <?php if (!empty($profile['logo_url'])): ?>
        <img src="<?= htmlspecialchars($profile['logo_url']) ?>" alt="Logo">
        <?php endif; ?>
        <h1><?= htmlspecialchars($displayName) ?></h1>
        <p>Online Admission Form — Session <?= htmlspecialchars($session_year) ?></p>
    </div>

    <div class="of-card" id="formCard">
        <h2><i class="fa-solid fa-user-plus"></i> Student Admission Application</h2>

        <div id="formAlert" class="of-alert"></div>

        <form id="admissionForm">
            <div class="of-section"><i class="fa-solid fa-user"></i> Student Information</div>
            <div class="of-grid">
                <div class="of-fg"><label>Student Name <span class="req">*</span></label><input type="text" name="student_name" required></div>
                <div class="of-fg">
                    <label>Class Applying For <span class="req">*</span></label>
                    <select name="class" required>
                        <option value="">-- Select Class --</option>
                        <?php foreach ($classes as $c): ?>
                        <option value="<?= htmlspecialchars(str_replace('Class ', '', $c['class_name'])) ?>"><?= htmlspecialchars($c['class_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="of-fg"><label>Date of Birth</label><input type="date" name="dob"></div>
                <div class="of-fg">
                    <label>Gender</label>
                    <select name="gender"><option value="">-- Select --</option><option>Male</option><option>Female</option><option>Other</option></select>
                </div>
                <div class="of-fg"><label>Blood Group</label><input type="text" name="blood_group" placeholder="e.g. O+"></div>
                <div class="of-fg"><label>Category</label><input type="text" name="category" placeholder="e.g. General, OBC"></div>
                <div class="of-fg"><label>Religion</label><input type="text" name="religion"></div>
                <div class="of-fg"><label>Nationality</label><input type="text" name="nationality" value="Indian"></div>
            </div>

            <div class="of-section"><i class="fa-solid fa-people-roof"></i> Parent / Guardian</div>
            <div class="of-grid">
                <div class="of-fg"><label>Parent / Guardian Name <span class="req">*</span></label><input type="text" name="parent_name" required></div>
                <div class="of-fg"><label>Phone <span class="req">*</span></label><input type="tel" name="phone" required></div>
                <div class="of-fg"><label>Father's Name</label><input type="text" name="father_name"></div>
                <div class="of-fg"><label>Father's Occupation</label><input type="text" name="father_occupation"></div>
                <div class="of-fg"><label>Mother's Name</label><input type="text" name="mother_name"></div>
                <div class="of-fg"><label>Mother's Occupation</label><input type="text" name="mother_occupation"></div>
                <div class="of-fg of-fg-full"><label>Email</label><input type="email" name="email"></div>
            </div>

            <div class="of-section"><i class="fa-solid fa-location-dot"></i> Address</div>
            <div class="of-fg"><label>Street Address</label><input type="text" name="address"></div>
            <div class="of-grid">
                <div class="of-fg"><label>City</label><input type="text" name="city"></div>
                <div class="of-fg"><label>State</label><input type="text" name="state"></div>
                <div class="of-fg"><label>Pincode</label><input type="text" name="pincode"></div>
            </div>

            <div class="of-section"><i class="fa-solid fa-school"></i> Previous School</div>
            <div class="of-grid">
                <div class="of-fg"><label>School Name</label><input type="text" name="previous_school"></div>
                <div class="of-fg"><label>Class</label><input type="text" name="previous_class"></div>
            </div>

            <div class="of-fg"><label>Additional Notes</label><textarea name="notes" rows="3" placeholder="Any additional information..."></textarea></div>

            <button type="submit" class="of-submit" id="submitBtn">
                <i class="fa-solid fa-paper-plane"></i> Submit Application
            </button>
        </form>
    </div>

    <div class="of-success" id="successPanel">
        <i class="fa-solid fa-circle-check"></i>
        <h2>Application Submitted!</h2>
        <p>Your application ID is: <strong id="appIdDisplay"></strong></p>
        <p>The school will review your application and contact you shortly.</p>
        <button onclick="location.reload()"><i class="fa-solid fa-plus" style="margin-right:6px;"></i> Submit Another</button>
    </div>

    <div class="of-footer">Powered by SchoolX ERP</div>
</div>

<script>
var csrfName  = document.querySelector('meta[name="csrf-name"]').content;
var csrfToken = document.querySelector('meta[name="csrf-token"]').content;

document.getElementById('admissionForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = document.getElementById('submitBtn');
    btn.disabled = true; btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Submitting...';

    var fd = new FormData(this);
    fd.append(csrfName, csrfToken);

    fetch('<?= base_url("admission_crm/submit_online_form") ?>', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
        body: new URLSearchParams(fd).toString()
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Application';
        if (data.status === 'success') {
            document.getElementById('formCard').style.display = 'none';
            document.getElementById('successPanel').style.display = 'block';
            document.getElementById('appIdDisplay').textContent = data.application_id || '';
        } else {
            var al = document.getElementById('formAlert');
            al.className = 'of-alert of-alert-error';
            al.textContent = data.message;
            al.style.display = 'block';
        }
    })
    .catch(function() {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa-solid fa-paper-plane"></i> Submit Application';
        var al = document.getElementById('formAlert');
        al.className = 'of-alert of-alert-error';
        al.textContent = 'Submission failed. Please try again.';
        al.style.display = 'block';
    });
});
</script>
</body>
</html>
