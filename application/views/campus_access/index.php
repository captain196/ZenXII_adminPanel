<?php
// Campus Access & Visitor Management — front desk console (MVP, 2026-08-09).
// Renamed from views/campus_access/index.php 2026-08-09 (module identity rename —
// "Campus Access" is the umbrella, Visitor Management is a sub-domain here).
// Mirrors Transport's view conventions for CSS (rbac_ui_kit.css tokens,
// vis- prefixed scoped classes, parallel to Transport's trn- prefix), but
// NOT its jQuery AJAX pattern: this content view renders BEFORE footer.php
// loads jquery.min.js, so a jQuery-based script here throws "$ is not
// defined" at parse time (caught via live browser console, 2026-08-09).
// Uses vanilla fetch() instead — already globally CSRF-patched in
// header.php, so no manual token handling is needed — plus the themed
// window.ZXLoader (application/views/include/zenxii_loader.php) for
// loading states instead of a plain "Loading…" placeholder.
$can_edit   = function_exists('has_permission') ? has_permission('Campus Access', 'edit')   : true;
$can_manage = function_exists('has_permission') ? has_permission('Campus Access', 'manage') : true;
?>
<link rel="stylesheet" href="<?= base_url('assets/css/rbac_ui_kit.css') ?>">
<style>
.vis-wrap{padding:20px;max-width:1400px;margin:0 auto}
.vis-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:24px;flex-wrap:wrap;gap:12px}
.vis-header-icon{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.vis-header-icon i{color:var(--gold);font-size:1.1rem}
.vis-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.vis-breadcrumb a{color:var(--gold);text-decoration:none}
.vis-breadcrumb li+li::before{content:"›";margin-right:6px;color:var(--t4)}
.vis-tabs{display:flex;gap:4px;margin-bottom:24px;border-bottom:1px solid var(--border);overflow-x:auto}
.vis-tab{padding:10px 16px;font-size:13px;font-weight:600;color:var(--t3);text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;cursor:pointer;font-family:var(--font-b)}
.vis-tab:hover{color:var(--t1)} .vis-tab.active{color:var(--gold);border-bottom-color:var(--gold)} .vis-tab i{margin-right:6px}
.vis-panel{display:none} .vis-panel.active{display:block}
.vis-card{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:20px;margin-bottom:18px}
.vis-card-title{font-family:var(--font-b);font-size:14px;font-weight:700;color:var(--t1);margin-bottom:14px;display:flex;align-items:center;justify-content:space-between}
.vis-stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:18px}
.vis-stat{background:var(--card);border:1px solid var(--border);border-radius:var(--r);padding:14px 16px}
.vis-stat .k{font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:var(--t3);margin-bottom:6px}
.vis-stat .v{font-family:var(--font-b);font-size:26px;font-weight:700}
.vis-btn{padding:8px 16px;border-radius:var(--r-sm);font-size:13px;font-weight:600;cursor:pointer;border:none;font-family:var(--font-b)}
.vis-btn-primary{background:var(--gold);color:#fff} .vis-btn-primary:hover{background:var(--gold2)}
.vis-btn-danger{background:var(--rose);color:#fff}
.vis-btn-sm{padding:5px 11px;font-size:12px}
.vis-table{width:100%;border-collapse:collapse;font-size:13px;font-family:var(--font-b)}
.vis-table th,.vis-table td{padding:10px 12px;text-align:left;border-bottom:1px solid var(--border)}
.vis-table th{color:var(--t3);font-weight:700;font-size:12px;text-transform:uppercase;letter-spacing:.04em}
.vis-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:12px;font-weight:700}
.vis-badge-green{background:rgba(34,197,94,.12);color:#22c55e}
.vis-badge-amber{background:rgba(245,158,11,.12);color:#f59e0b}
.vis-badge-red{background:rgba(239,68,68,.12);color:#ef4444}
.vis-badge-grey{background:rgba(107,114,128,.14);color:#6b7280}
.vis-form-group{margin-bottom:14px}
.vis-form-group label{display:block;font-size:12px;font-weight:600;color:var(--t3);margin-bottom:4px;text-transform:uppercase;letter-spacing:.3px}
.vis-form-group input,.vis-form-group select,.vis-form-group textarea{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:13px}
.vis-form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px}
/* UI hardening (2026-08-10): no responsive breakpoint existed anywhere in
   this file — every form's paired fields stayed a fixed 2-column grid and
   the Front Desk stat cards stayed a fixed 4-column grid regardless of
   viewport width. On a front-desk tablet in portrait (or any narrow
   screen), that cramps every input in this module's 4 forms (check-in,
   gate pass, late entry, watchlist) into unusably narrow columns. */
@media (max-width: 640px) {
  .vis-form-row{grid-template-columns:1fr}
  .vis-stat-grid{grid-template-columns:repeat(2,1fr)}
}
.vis-watchflag{margin:0 0 16px;padding:11px 14px;border-radius:8px;background:rgba(239,68,68,.1);color:#ef4444;font-size:12.5px;font-weight:600;display:none}
.vis-empty{padding:24px;text-align:center;color:var(--t3);font-size:13px}
.ci-photo-live-wrap{position:relative;width:220px;height:270px;border-radius:14px;overflow:hidden;background:#0b0c0a;flex:none;border:1px solid rgba(255,255,255,.08);box-shadow:0 14px 32px -16px rgba(0,0,0,.45)}
.ci-photo-live-wrap video{width:100%;height:100%;object-fit:cover;display:block;transform:scaleX(-1)}
.ci-photo-live-badge{position:absolute;top:10px;left:10px;display:flex;align-items:center;gap:5px;padding:3px 9px 3px 7px;border-radius:20px;background:rgba(0,0,0,.55);backdrop-filter:blur(3px);font-size:10px;font-weight:700;letter-spacing:.06em;color:#fff;text-transform:uppercase}
.ci-photo-live-dot{width:6px;height:6px;border-radius:50%;background:#ef4444;animation:ci-live-pulse 1.6s ease infinite}
@keyframes ci-live-pulse{0%,100%{opacity:1}50%{opacity:.3}}
.ci-photo-oval{position:absolute;left:50%;top:44%;transform:translate(-50%,-50%);width:148px;height:188px;border-radius:50%;border:2.5px solid rgba(255,255,255,.6);box-shadow:0 0 0 999px rgba(0,0,0,.52);pointer-events:none;transition:border-color .25s ease,box-shadow .25s ease}
.ci-photo-oval::before{content:'';position:absolute;inset:-7px;border-radius:50%;border:1px solid rgba(255,255,255,.16);transition:border-color .25s ease}
.ci-photo-oval.ready{border-color:#22c55e;box-shadow:0 0 0 999px rgba(0,0,0,.52),0 0 22px 3px rgba(34,197,94,.55)}
.ci-photo-oval.ready::before{border-color:rgba(34,197,94,.4)}
.ci-photo-hint{position:absolute;left:50%;bottom:12px;transform:translateX(-50%);max-width:calc(100% - 24px);padding:5px 12px;border-radius:20px;background:rgba(0,0,0,.55);backdrop-filter:blur(3px);color:#fff;font-size:11px;font-weight:600;text-align:center;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;pointer-events:none}
#ciPhotoBox.has-photo{border-style:solid;border-color:var(--border)}
.vis-btn.is-busy{cursor:wait;opacity:.85}
.vis-spinner{display:inline-block;width:11px;height:11px;border:2px solid currentColor;border-right-color:transparent;border-radius:50%;animation:vis-spin .6s linear infinite;vertical-align:-1.5px;margin-right:5px}
@keyframes vis-spin{to{transform:rotate(360deg)}}

.qr-modal-overlay{display:none;position:fixed;inset:0;background:rgba(20,22,18,.55);backdrop-filter:blur(2px);z-index:1000;align-items:center;justify-content:center;padding:16px}
.qr-modal-card{background:var(--card,#fff);border:1px solid var(--border);border-radius:16px;padding:22px;max-width:380px;width:100%;box-shadow:0 24px 60px -20px rgba(0,0,0,.35)}
.qr-modal-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:16px}
.qr-modal-title{font-family:var(--font-b);font-size:15px;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.qr-modal-title i{color:var(--gold)}
.qr-modal-close{background:none;border:none;font-size:20px;line-height:1;color:var(--t3);cursor:pointer;padding:2px 6px;border-radius:6px}
.qr-modal-close:hover{background:var(--surface-alt,#f6f7f3);color:var(--t1)}
.qr-modal-error{display:none;margin-bottom:12px;padding:9px 12px;border-radius:8px;background:rgba(239,68,68,.1);color:#ef4444;font-size:12.5px;font-weight:600}
.qr-camera-box{position:relative;width:100%;aspect-ratio:1/1;border-radius:12px;overflow:hidden;background:#111}
.qr-camera-box video{width:100%;height:100%;object-fit:cover;display:block}
.qr-camera-frame{position:absolute;inset:14%;border:3px solid var(--gold,#BC5A3C);border-radius:12px;pointer-events:none;box-shadow:0 0 0 999px rgba(0,0,0,.4)}
.qr-hint{font-size:12px;color:var(--t3);margin-top:10px;text-align:center}
.qr-paste-row{display:none;gap:8px;margin-top:12px}
.qr-paste-row input{flex:1;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:13px;font-family:var(--font-mono,monospace)}
.qr-link-btn{display:block;margin:12px auto 0;background:none;border:none;color:var(--gold);font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:underline;text-underline-offset:2px}
.qr-confirm-person{display:flex;align-items:center;gap:12px;padding:14px;border:1px solid var(--border);border-radius:10px;background:var(--surface-alt,#f6f7f3)}
.qr-confirm-avatar{width:44px;height:44px;border-radius:50%;flex:none;overflow:hidden;background:var(--gold-dim,rgba(188,90,60,.14));color:var(--gold,#BC5A3C);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:15px}
.qr-confirm-avatar img{width:100%;height:100%;object-fit:cover}
.qr-confirm-name{font-weight:700;font-size:14px;color:var(--t1)}
.qr-confirm-meta{font-size:12px;color:var(--t3);margin-top:2px}
.qr-confirm-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:16px}
.gen-modal-message{font-size:13px;color:var(--t2);margin-bottom:14px;line-height:1.5}
.gen-field{margin-bottom:12px}
.gen-field label{display:block;font-size:11.5px;font-weight:600;color:var(--t3);margin-bottom:4px;text-transform:uppercase;letter-spacing:.03em}
.gen-field input,.gen-field select,.gen-field textarea{width:100%;padding:8px 10px;border:1px solid var(--border);border-radius:6px;background:var(--bg);color:var(--t1);font-size:13px;font-family:inherit}
.gen-field textarea{resize:vertical;min-height:56px}
.vis-skel{display:inline-block;height:12px;border-radius:4px;background:linear-gradient(90deg,var(--surface-alt,#eceee6) 25%,var(--border,#e1e2d8) 37%,var(--surface-alt,#eceee6) 63%);background-size:400% 100%;animation:vis-skel-shimmer 1.4s ease infinite}
@keyframes vis-skel-shimmer{0%{background-position:100% 50%}100%{background-position:0 50%}}
</style>

<div class="content-wrapper">
<section class="content"><div class="vis-wrap">
  <div class="vis-header"><div>
    <div class="vis-header-icon"><i class="fa fa-id-badge"></i> Campus Access Management</div>
    <ol class="vis-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('operations') ?>">Operations</a></li><li>Campus Access</li></ol>
  </div></div>

  <div class="vis-tabs">
    <div class="vis-tab active" data-panel="panelDesk"><i class="fa fa-th-large"></i>Front Desk</div>
    <div class="vis-tab" data-panel="panelCheckin"><i class="fa fa-plus"></i>Visitor Check-In</div>
    <div class="vis-tab" data-panel="panelGatepass"><i class="fa fa-child"></i>Student/Staff Gate Pass</div>
    <div class="vis-tab" data-panel="panelMuster"><i class="fa fa-bars"></i>Muster / Roll-Call</div>
    <div class="vis-tab" data-panel="panelLog"><i class="fa fa-list"></i>Log &amp; Export</div>
    <?php if ($can_manage): ?><div class="vis-tab" data-panel="panelWatch"><i class="fa fa-flag"></i>Watchlist</div><?php endif; ?>
  </div>

  <!-- FRONT DESK -->
  <div class="vis-panel active" id="panelDesk">
    <div class="vis-stat-grid">
      <div class="vis-stat"><div class="k">On campus now</div><div class="v" id="statOnCampus">—</div></div>
      <div class="vis-stat"><div class="k">Pending approval</div><div class="v" id="statPending">—</div></div>
      <div class="vis-stat"><div class="k">Overstay</div><div class="v" id="statOverstay">—</div></div>
      <div class="vis-stat"><div class="k">On this list</div><div class="v" id="statTotal">—</div></div>
    </div>
    <div class="vis-card">
      <div class="vis-card-title">Today at the gate <span>
        <?php if ($can_edit): ?><button class="vis-btn vis-btn-sm" onclick="visScanCheckout()"><i class="fa fa-qrcode"></i> Scan QR to check out</button><?php endif; ?>
        <button class="vis-btn vis-btn-sm" data-busy-label="Refreshing…" onclick="visRefreshDesk(this)"><i class="fa fa-refresh"></i></button>
      </span></div>
      <div id="deskTableWrap" style="overflow-x:auto;position:relative;min-height:80px"><table class="vis-table">
        <thead><tr><th>Visitor</th><th>Purpose</th><th>Host</th><th>Status</th><th>Check-In</th><th>Actions</th></tr></thead>
        <tbody id="deskRows"></tbody>
      </table></div>
    </div>
  </div>

  <!-- CHECK-IN -->
  <div class="vis-panel" id="panelCheckin">
    <div class="vis-card">
      <div class="vis-card-title">New visitor check-in</div>
      <div class="vis-watchflag" id="ciWatchFlag"></div>
      <form id="ciForm">
        <div class="vis-form-row">
          <div class="vis-form-group"><label>Visitor name *</label><input type="text" name="name" id="ciName" required></div>
          <div class="vis-form-group"><label>Phone *</label><input type="text" name="phone" id="ciPhone" required></div>
        </div>
        <div class="vis-form-row">
          <div class="vis-form-group"><label>ID proof type</label>
            <select name="idProofType" id="ciIdProofType"><option value="">—</option><option>Aadhaar</option><option>Driving Licence</option><option>PAN</option><option>Voter ID</option><option>Passport</option><option>Other</option></select>
          </div>
          <div class="vis-form-group"><label>ID proof number</label>
            <input type="text" name="idProofNumber" id="ciIdProofNumber" placeholder="Select a type first" disabled>
            <span id="ciIdProofHint" style="display:block;font-size:11px;color:var(--t3,#767C88);margin-top:4px">Stored as last 4 characters only — the full number is never kept as text.</span>
          </div>
        </div>
        <div class="vis-form-group">
          <label>Visitor photo</label>
          <div style="display:flex;gap:14px;align-items:flex-start;flex-wrap:wrap">
            <div id="ciPhotoBox" style="width:112px;height:112px;border:1.5px dashed var(--border);border-radius:8px;display:flex;align-items:center;justify-content:center;overflow:hidden;background:var(--surface-alt,#f6f7f3);flex:none">
              <span id="ciPhotoPlaceholder" style="font-size:10.5px;color:var(--t3);text-align:center;padding:6px">No photo</span>
              <img id="ciPhotoImg" style="display:none;width:100%;height:100%;object-fit:cover" alt="Captured visitor photo">
            </div>
            <div id="ciPhotoLiveWrap" class="ci-photo-live-wrap" style="display:none">
              <video id="ciPhotoVideo" autoplay playsinline muted></video>
              <div class="ci-photo-live-badge"><span class="ci-photo-live-dot"></span>Live</div>
              <div class="ci-photo-oval" id="ciPhotoOval"></div>
              <div class="ci-photo-hint" id="ciPhotoHint">Center your face in the oval, then Capture</div>
            </div>
            <div style="display:flex;flex-direction:column;gap:8px;justify-content:center">
              <button type="button" class="vis-btn vis-btn-sm" id="ciPhotoOpenBtn" data-busy-label="Opening camera…">📷 Open camera</button>
              <button type="button" class="vis-btn vis-btn-sm vis-btn-primary" id="ciPhotoSnapBtn" style="display:none" data-busy-label="Capturing…">Capture</button>
              <button type="button" class="vis-btn vis-btn-sm" id="ciPhotoRetakeBtn" style="display:none" data-busy-label="Retaking…">Retake</button>
              <span style="font-size:11px;color:var(--t3,#767C88)">Optional — shown on the printed gate pass.</span>
            </div>
          </div>
          <canvas id="ciPhotoCanvas" style="display:none"></canvas>
        </div>
        <div class="vis-form-row">
          <div class="vis-form-group"><label>Visitor type</label>
            <select name="visitorType"><option>Guardian / Parent</option><option>Vendor</option><option>Contractor</option><option>Delivery</option><option>Government / Inspection</option><option>Alumni</option><option>Admission Enquiry</option><option>Other</option></select>
          </div>
          <div class="vis-form-group"><label>Organization</label><input type="text" name="organization"></div>
        </div>
        <div class="vis-form-row">
          <div class="vis-form-group"><label>Purpose *</label>
            <select name="purpose" id="ciPurpose" required>
              <option value="">Select…</option>
              <option>Meet Staff / Teacher</option><option>Admission Enquiry</option><option>Delivery</option>
              <option>Vendor / Maintenance</option><option>Event / PTM</option><option>Government / Inspection</option><option>Other</option>
            </select>
            <div id="ciPurposeNoteWrap" style="display:none;margin-top:8px">
              <input type="text" name="purposeNote" id="ciPurposeNote" placeholder="Please specify the purpose">
            </div>
          </div>
          <div class="vis-form-group" style="position:relative"><label>Host staff</label>
            <input type="text" id="ciHostSearch" placeholder="Search staff by name" autocomplete="off">
            <input type="hidden" name="hostStaffId" id="ciHostId">
            <input type="hidden" name="hostStaffName" id="ciHostName">
            <div id="ciHostResults" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2,#fff);border:1px solid var(--border);border-radius:6px;max-height:180px;overflow-y:auto;z-index:50"></div>
          </div>
        </div>
        <div class="vis-form-row">
          <div class="vis-form-group"><label>Vehicle number</label><input type="text" name="vehicleNumber"></div>
          <div class="vis-form-group"><label>Expected duration (minutes)</label><input type="number" name="expectedDurationMinutes" value="60" min="5"></div>
        </div>
        <div class="vis-form-group"><label>Accompanying visitors</label>
          <div style="display:flex;align-items:center;gap:10px">
            <button type="button" class="vis-btn vis-btn-sm" id="ciAccMinus">−</button>
            <span id="ciAccN" style="font-family:var(--font-mono);font-weight:700;min-width:1.4em;text-align:center">0</span>
            <button type="button" class="vis-btn vis-btn-sm" id="ciAccPlus">+</button>
            <input type="hidden" name="accompanying" id="ciAccInput" value="0">
          </div>
        </div>
        <div class="vis-form-group"><label>Notes</label><textarea name="notes" rows="2"></textarea></div>
        <div style="display:flex;justify-content:flex-end;gap:10px">
          <button type="submit" class="vis-btn vis-btn-primary" data-busy-label="Checking in…" <?= $can_edit ? '' : 'disabled' ?>>Check in visitor →</button>
        </div>
      </form>
    </div>
  </div>

  <!-- GATE PASS (student/staff — separate policy from Visitor Management above) -->
  <div class="vis-panel" id="panelGatepass">
    <p class="hint" style="margin:0 0 16px;">Student early-leave requires <strong>both</strong> the class teacher and the parent to approve — never auto-approved. Staff early-leave needs a single admin approval. Late entry is logged directly, no approval needed. Approval and physical gate release are always two separate steps.</p>

    <div class="vis-card">
      <div class="vis-card-title">Raise an early-leave request</div>
      <form id="gpForm">
        <div class="vis-form-row">
          <div class="vis-form-group"><label>Person type</label>
            <select name="personType" id="gpPersonType"><option value="student">Student</option><option value="staff">Staff</option></select>
          </div>
          <div class="vis-form-group" style="position:relative"><label>Person</label>
            <input type="text" id="gpPersonSearch" placeholder="Search by name" autocomplete="off">
            <input type="hidden" name="personId" id="gpPersonId">
            <input type="hidden" name="personName" id="gpPersonName">
            <div id="gpPersonResults" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2,#fff);border:1px solid var(--border);border-radius:6px;max-height:180px;overflow-y:auto;z-index:50"></div>
          </div>
        </div>
        <div class="vis-form-row" id="gpTeacherRow">
          <div class="vis-form-group" style="position:relative"><label>Class teacher</label>
            <input type="text" id="gpTeacherSearch" placeholder="Search staff by name" autocomplete="off">
            <input type="hidden" name="teacherStaffId" id="gpTeacherId">
            <div id="gpTeacherResults" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2,#fff);border:1px solid var(--border);border-radius:6px;max-height:180px;overflow-y:auto;z-index:50"></div>
          </div>
          <div class="vis-form-group"><label>Requested exit time</label><input type="time" name="requestedTime"></div>
        </div>
        <div class="vis-form-group"><label>Reason *</label><input type="text" name="reason" required></div>
        <div style="display:flex;justify-content:flex-end"><button type="submit" class="vis-btn vis-btn-primary" data-busy-label="Sending…" <?= $can_edit ? '' : 'disabled' ?>>Send for approval →</button></div>
      </form>
    </div>

    <div class="vis-card">
      <div class="vis-card-title">Log a late entry <span class="hint">— no approval needed, just a record</span></div>
      <form id="leForm">
        <div class="vis-form-row">
          <div class="vis-form-group"><label>Person type</label>
            <select name="personType" id="lePersonType"><option value="student">Student</option><option value="staff">Staff</option></select>
          </div>
          <div class="vis-form-group" style="position:relative"><label>Person</label>
            <input type="text" id="lePersonSearch" placeholder="Search by name" autocomplete="off">
            <input type="hidden" name="personId" id="lePersonId">
            <input type="hidden" name="personName" id="lePersonName">
            <div id="lePersonResults" style="display:none;position:absolute;top:100%;left:0;right:0;background:var(--bg2,#fff);border:1px solid var(--border);border-radius:6px;max-height:180px;overflow-y:auto;z-index:50"></div>
          </div>
        </div>
        <div class="vis-form-group"><label>Reason</label><input type="text" name="reason" placeholder="Optional — e.g. traffic, appointment"></div>
        <div style="display:flex;justify-content:flex-end"><button type="submit" class="vis-btn vis-btn-primary" data-busy-label="Logging…" <?= $can_edit ? '' : 'disabled' ?>>Log arrival</button></div>
      </form>
    </div>

    <div class="vis-card">
      <div class="vis-card-title">Today's gate passes <button class="vis-btn vis-btn-sm" data-busy-label="Refreshing…" onclick="visRefreshGatePasses(this)"><i class="fa fa-refresh"></i></button></div>
      <p class="hint" style="margin:-6px 0 12px;">Today's activity, plus anything still pending approval or awaiting release from before today — rows older than today are marked with how long ago they were last touched.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <select id="gpFilterStatus" style="padding:7px 10px;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--t1);font-size:12.5px">
          <option value="">All statuses</option>
          <option value="pending_approval">Pending approval</option>
          <option value="approved">Approved — awaiting release</option>
          <option value="released">Released</option>
          <option value="denied">Denied</option>
          <option value="logged">Logged (late entry)</option>
        </select>
      </div>
      <div id="gpTableWrap" style="overflow-x:auto;position:relative;min-height:80px"><table class="vis-table">
        <thead><tr><th>Person</th><th>Type</th><th>Reason</th><th>Approvals</th><th>Status</th><th></th></tr></thead>
        <tbody id="gpRows"></tbody>
      </table></div>
    </div>
  </div>

  <!-- MUSTER / ROLL-CALL -->
  <div class="vis-panel" id="panelMuster">
    <p class="hint" style="margin:0 0 16px;">On-demand — generated when you open this tab, not a persistent live feed. Real-time on-campus counts by person type from the unified access ledger. Full expected-roster reconciliation (enrolled vs. present vs. unaccounted) is a documented Phase 2 gap, not built yet.</p>
    <div class="vis-stat-grid" style="grid-template-columns:repeat(3,1fr);">
      <div class="vis-stat"><div class="k">Students on campus</div><div class="v" id="musterStudent">—</div></div>
      <div class="vis-stat"><div class="k">Staff on campus</div><div class="v" id="musterStaff">—</div></div>
      <div class="vis-stat"><div class="k">Visitors on campus</div><div class="v" id="musterVisitor">—</div></div>
    </div>
    <div class="vis-card">
      <div class="vis-card-title">Live roll-call <button class="vis-btn vis-btn-sm" data-busy-label="Refreshing…" onclick="visRefreshMuster(this)"><i class="fa fa-refresh"></i></button></div>
      <div id="musterMeta" class="hint" style="margin-bottom:10px;"></div>
      <div id="musterTableWrap" style="overflow-x:auto;position:relative;min-height:80px"><table class="vis-table">
        <thead><tr><th>Type</th><th>Name</th><th>ID</th><th>On campus since</th></tr></thead>
        <tbody id="musterRows"></tbody>
      </table></div>
    </div>
  </div>

  <!-- LOG -->
  <div class="vis-panel" id="panelLog">
    <div class="vis-card">
      <div class="vis-card-title">
        Visitor log
        <a class="vis-btn vis-btn-sm" id="logExportLink" href="<?= base_url('campus_access/export_log_csv') ?>"><i class="fa fa-download"></i> Export CSV</a>
      </div>
      <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:14px">
        <select id="logFilterStatus" style="padding:7px 10px;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--t1);font-size:12.5px">
          <option value="">All statuses</option>
          <option value="checked_in">On campus</option>
          <option value="pending_approval">Pending approval</option>
          <option value="overstay">Overstay</option>
          <option value="checked_out">Checked out</option>
          <option value="denied">Denied</option>
        </select>
        <select id="logFilterPurpose" style="padding:7px 10px;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--t1);font-size:12.5px">
          <option value="">All purposes</option>
          <option>Meet Staff / Teacher</option><option>Admission Enquiry</option><option>Delivery</option>
          <option>Vendor / Maintenance</option><option>Event / PTM</option><option>Government / Inspection</option><option>Other</option>
        </select>
      </div>
      <div id="logTableWrap" style="overflow-x:auto;position:relative;min-height:80px"><table class="vis-table">
        <thead><tr><th>Visitor</th><th>Purpose</th><th>Host</th><th>Status</th><th>In</th><th>Out</th><th>Watchlist</th></tr></thead>
        <tbody id="logRows"></tbody>
      </table></div>
    </div>
  </div>

  <?php if ($can_manage): ?>
  <!-- WATCHLIST -->
  <div class="vis-panel" id="panelWatch">
    <div class="vis-card">
      <div class="vis-card-title">Access by role</div>
      <p style="margin:-6px 0 12px;font-size:12.5px;color:var(--t3)">How RBAC gates this module — precise per-module grants, not the Operations umbrella.</p>
      <div style="display:flex;gap:10px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:7px;padding:6px 12px;border-radius:8px;background:var(--surface-alt);font-size:12px"><strong>Front Office</strong>&nbsp;— Campus Access · manage</div>
        <div style="display:flex;align-items:center;gap:7px;padding:6px 12px;border-radius:8px;background:var(--surface-alt);font-size:12px"><strong>Security</strong>&nbsp;— Campus Access · edit (check-in/out, gate pass, no watchlist)</div>
        <div style="display:flex;align-items:center;gap:7px;padding:6px 12px;border-radius:8px;background:var(--surface-alt);font-size:12px"><strong>Teacher / host staff</strong>&nbsp;— no grant needed, receives arrival/approval notices only</div>
        <div style="display:flex;align-items:center;gap:7px;padding:6px 12px;border-radius:8px;background:var(--surface-alt);font-size:12px"><strong>Admin</strong>&nbsp;— Campus Access · manage</div>
      </div>
    </div>
    <div class="vis-card">
      <div class="vis-card-title">Add a watchlist entry</div>
      <form id="wlForm">
        <div class="vis-form-row">
          <div class="vis-form-group"><label>Name</label><input type="text" name="name"></div>
          <div class="vis-form-group"><label>Phone</label><input type="text" name="phone"></div>
        </div>
        <div class="vis-form-row">
          <div class="vis-form-group"><label>Severity</label>
            <select name="severity"><option value="flag_for_review">Flag for review</option><option value="block">Block</option></select>
          </div>
          <div class="vis-form-group"><label>Expires (optional)</label><input type="date" name="expiresAt"></div>
        </div>
        <div class="vis-form-group"><label>Reason *</label><input type="text" name="reason" required></div>
        <div style="display:flex;justify-content:flex-end"><button type="submit" class="vis-btn vis-btn-primary" data-busy-label="Adding…">Add entry</button></div>
      </form>
    </div>
    <div class="vis-card">
      <div class="vis-card-title">Active entries</div>
      <div id="wlTableWrap" style="overflow-x:auto;position:relative;min-height:80px"><table class="vis-table">
        <thead><tr><th>Name</th><th>Phone</th><th>Severity</th><th>Reason</th><th>Added</th><th></th></tr></thead>
        <tbody id="wlRows"></tbody>
      </table></div>
    </div>
  </div>
  <?php endif; ?>

  <!-- QR check-out modal (Front Desk) — camera scan when the browser supports
       BarcodeDetector, a proper paste field otherwise. Two steps: scan/paste,
       then a person-confirm step — no native prompt()/alert()/confirm()
       anywhere in this flow. -->
  <div id="qrScanModal" class="qr-modal-overlay">
    <div class="qr-modal-card">
      <div class="qr-modal-head">
        <div class="qr-modal-title"><i class="fa fa-qrcode"></i> Check out a visitor</div>
        <button type="button" class="qr-modal-close" id="qrModalCloseBtn" aria-label="Close">&times;</button>
      </div>
      <div class="qr-modal-error" id="qrModalError"></div>

      <div id="qrStepScan">
        <div class="qr-camera-box" id="qrCameraBox">
          <video id="qrScanVideo" autoplay playsinline muted></video>
          <div class="qr-camera-frame"></div>
        </div>
        <div id="qrCameraHint" class="qr-hint">Point the camera at the QR on the printed badge.</div>
        <div class="qr-paste-row" id="qrPasteRow">
          <input type="text" id="qrPasteInput" placeholder="Paste the badge QR token">
          <button type="button" class="vis-btn vis-btn-primary vis-btn-sm" id="qrPasteLookupBtn" data-busy-label="Looking…">Look up</button>
        </div>
        <button type="button" class="qr-link-btn" id="qrTogglePasteBtn">Paste the code instead</button>
      </div>

      <div id="qrStepConfirm" style="display:none">
        <div class="qr-confirm-person">
          <div class="qr-confirm-avatar" id="qrConfirmAvatar"></div>
          <div>
            <div class="qr-confirm-name" id="qrConfirmName"></div>
            <div class="qr-confirm-meta" id="qrConfirmMeta"></div>
          </div>
        </div>
        <div class="qr-hint">Confirm this is the person physically in front of you before checking them out.</div>
        <div class="qr-confirm-actions">
          <button type="button" class="vis-btn vis-btn-sm" id="qrConfirmBackBtn">Back</button>
          <button type="button" class="vis-btn vis-btn-sm vis-btn-primary" id="qrConfirmOkBtn" data-busy-label="Checking out…">Confirm check-out</button>
        </div>
      </div>
    </div>
  </div>

  <!-- Generic themed modal — replaces every native prompt()/confirm()/alert()
       in this module (2026-08-10). One shell, driven entirely by JS: askText()
       for a text/textarea/select prompt, askConfirm() for a yes/no confirm,
       showAlert() for a must-acknowledge message. See the JS section for the
       Promise-based API these three functions expose. -->
  <div id="genModal" class="qr-modal-overlay">
    <div class="qr-modal-card">
      <div class="qr-modal-head">
        <div class="qr-modal-title" id="genModalTitle"></div>
        <button type="button" class="qr-modal-close" id="genModalCloseBtn" aria-label="Close">&times;</button>
      </div>
      <div class="qr-modal-error" id="genModalError"></div>
      <div class="gen-modal-message" id="genModalMessage" style="display:none"></div>
      <div id="genModalFields"></div>
      <div class="qr-confirm-actions">
        <button type="button" class="vis-btn vis-btn-sm" id="genModalCancelBtn">Cancel</button>
        <button type="button" class="vis-btn vis-btn-sm vis-btn-primary" id="genModalOkBtn">OK</button>
      </div>
    </div>
  </div>

</div></section></div>

<script>
(function(){
  // No jQuery here on purpose — this view renders BEFORE footer.php loads
  // jquery.min.js, so any $(...) call at parse time throws "$ is not
  // defined". fetch() is already globally patched in header.php (CSRF
  // auto-attached to POST bodies, session-expiry redirect on 401/403), so
  // it needs zero manual CSRF handling here — that patch does it.
  var BASE = <?= json_encode(base_url()) ?>;
  var CAN_EDIT = <?= $can_edit ? 'true' : 'false' ?>;

  function esc(s) {
    var d = document.createElement('div');
    d.textContent = (s == null ? '' : String(s));
    return d.innerHTML;
  }

  /**
   * Shimmering skeleton placeholder rows — shown the INSTANT a refresh
   * starts (synchronously, before the fetch even fires), replacing the old
   * pattern of an empty <tbody> with a floating "Loading X…" message
   * covering it. That combination looked broken whenever the shared
   * ZXLoader animation was slow to initialize (deferred lottie.min.js) —
   * the message text would render alone with nothing underneath it. A
   * pure-CSS skeleton has no such dependency and always renders instantly.
   * widths: array of per-column bar widths in px (roughly matches real
   * content width so the placeholder reads as "this table", not generic).
   */
  function skeletonRows(widths, rowCount) {
    rowCount = rowCount || 3;
    var row = '<tr>' + widths.map(function(w){
      return '<td><span class="vis-skel" style="width:' + w + 'px"></span></td>';
    }).join('') + '</tr>';
    return new Array(rowCount + 1).join(row);
  }

  function initials(name) {
    var parts = (name || '').trim().split(/\s+/).filter(Boolean);
    if (!parts.length) return '?';
    return (parts[0][0] + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase();
  }
  /** Small round avatar — the captured photo when present, initials otherwise. */
  function avatarCell(name, photoUrl) {
    var av = photoUrl
      ? '<img src="' + esc(photoUrl) + '" alt="" style="width:26px;height:26px;border-radius:50%;object-fit:cover;flex:none">'
      : '<span style="width:26px;height:26px;border-radius:50%;background:var(--gold-dim,rgba(188,90,60,.14));color:var(--gold,#BC5A3C);display:inline-flex;align-items:center;justify-content:center;font-size:10.5px;font-weight:700;flex:none">' + esc(initials(name)) + '</span>';
    return '<div style="display:flex;align-items:center;gap:9px">' + av + '<span>' + esc(name) + '</span></div>';
  }
  /**
   * purposeNote was captured at check-in but never displayed anywhere — the
   * Purpose column just showed the bare "Other" category with the typed
   * detail silently discarded from view. The filter dropdown correctly
   * stays grouped by the fixed "Other" category (you can't filter free
   * text), but the column itself should show what was actually typed.
   */
  function purposeLabel(r) {
    var p = esc(r.purpose);
    if (r.purpose === 'Other' && r.purposeNote) {
      return p + ' <span style="color:var(--t3)">— ' + esc(r.purposeNote) + '</span>';
    }
    return p;
  }

  /**
   * BUG-055 fix (2026-08-10): this used to resolve on ANY response, so a
   * 403 (session expired) / 500 / json_error() was silently rendered by
   * every caller as an empty, successful result ("No one on campus right
   * now." instead of a visible error) — phantom success on the read side.
   * Now matches postForm()'s existing check exactly: reject on !r.ok or
   * {status:'error'}, same contract (json_success()/json_error() in
   * MY_Controller.php), same error message shape. Every core list view
   * already ends in .catch(fail) (visRefreshDesk/GatePasses/Muster/Log/
   * Watchlist), so those five now surface real errors automatically. The
   * live-typeahead helpers (watchlist preview, staff/student search) keep
   * their existing soft-degrade .catch() — hiding a suggestion dropdown on
   * failure is correct UX for a live-as-you-type hint, not a phantom-success
   * claim, so those are intentionally left alone.
   */
  function get(url, params) {
    var qs = params ? '?' + new URLSearchParams(params).toString() : '';
    return fetch(url + qs, {headers: {'X-Requested-With': 'XMLHttpRequest'}})
      .then(function(r){
        return r.json().then(function(j){
          if (!r.ok || (j && j.status === 'error')) throw new Error((j && j.message) || 'Something went wrong.');
          return j;
        });
      });
  }

  /** POST as a urlencoded body — fetch() is patched globally to append CSRF to a string body. */
  function postForm(url, params) {
    return fetch(url, {
      method: 'POST',
      headers: {'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest'},
      body: new URLSearchParams(params || {}).toString(),
    }).then(function(r){
      return r.json().then(function(j){
        if (!r.ok || (j && j.status === 'error')) throw new Error((j && j.message) || 'Something went wrong.');
        return j;
      });
    });
  }

  function fail(err) { showAlert({title: 'Something went wrong', message: (err && err.message) || 'Something went wrong.'}); }

  /**
   * Every submit/action button in this module fires a fetch() with no
   * visual feedback while it's in flight — the button just sits there,
   * which reads as unresponsive and invites a double-click (a real risk on
   * a non-idempotent action like check-in). This disables the button, swaps
   * its label for a spinner, and restores it once the promise settles.
   * Safe to call on a button that gets destroyed by a table re-render mid-
   * flight (visRefreshDesk() etc.) — restoring state on a detached node is
   * a harmless no-op; the row simply isn't there to show it on.
   */
  function withBusy(btn, task) {
    if (!btn) return task();
    var original = btn.innerHTML;
    btn.disabled = true;
    btn.classList.add('is-busy');
    btn.innerHTML = '<span class="vis-spinner"></span>' + (btn.dataset.busyLabel || 'Working…');
    function restore() { btn.disabled = false; btn.classList.remove('is-busy'); btn.innerHTML = original; }
    return task().then(function(res){ restore(); return res; }, function(err){ restore(); throw err; });
  }

  /**
   * Checkout is the one action whose row VANISHES from "Today at the gate"
   * on success (checked_out was never part of listOnCampus()'s status
   * filter — the row's real destination is the Log & Export tab). With no
   * confirm() dialog and no visible badge change on this screen, a plain
   * disappearing row reads as "did that even work?" — this toast is the fix.
   */
  function toast(message) {
    var el = document.createElement('div');
    el.textContent = message;
    el.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translateX(-50%);background:#1C2230;color:#fff;padding:10px 18px;border-radius:8px;font-size:13px;font-weight:600;box-shadow:0 8px 24px -8px rgba(0,0,0,.4);z-index:2000;opacity:0;transition:opacity .2s ease';
    document.body.appendChild(el);
    requestAnimationFrame(function(){ el.style.opacity = '1'; });
    setTimeout(function(){
      el.style.opacity = '0';
      setTimeout(function(){ el.remove(); }, 250);
    }, 2200);
  }

  /**
   * Generic themed modal — replaces every native prompt()/confirm()/alert()
   * in this module (2026-08-10). Reuses the same #genModal shell for three
   * Promise-based flavors:
   *   askText({title, message, fields, okLabel, cancelLabel, danger})
   *     → Promise<{fieldName: value}|null>  (null = cancelled)
   *   askConfirm({title, message, okLabel, cancelLabel, danger})
   *     → Promise<boolean>
   *   showAlert('message') or showAlert({title, message, danger})
   *     → Promise<void> (resolves once acknowledged; no Cancel button)
   * fields: [{name, label, type:'text'|'textarea'|'select', required,
   *           placeholder, default, options:[{value,label}]}]
   * Only one instance can be open at a time (matches the sequential-dialog
   * shape the native versions had — a caller awaits one before opening the
   * next, e.g. the delegate-reason retry in visGpDecide below).
   */
  var genModal = document.getElementById('genModal');
  var genModalTitle = document.getElementById('genModalTitle');
  var genModalError = document.getElementById('genModalError');
  var genModalMessage = document.getElementById('genModalMessage');
  var genModalFields = document.getElementById('genModalFields');
  var genModalCancelBtn = document.getElementById('genModalCancelBtn');
  var genModalOkBtn = document.getElementById('genModalOkBtn');
  var genModalCloseBtn = document.getElementById('genModalCloseBtn');
  var genResolve = null;
  var genFieldsConfig = [];
  var genMode = 'prompt';

  function genShowError(msg) { genModalError.textContent = msg; genModalError.style.display = 'block'; }
  function genClearError() { genModalError.style.display = 'none'; genModalError.textContent = ''; }
  function genFinish(result) {
    genModal.style.display = 'none';
    var resolve = genResolve;
    genResolve = null;
    if (resolve) resolve(result);
  }
  var genOpenedAt = 0;
  function genOpen(mode, opts) {
    return new Promise(function(resolve){
      genOpenedAt = Date.now();
      genResolve = resolve;
      genMode = mode;
      genFieldsConfig = opts.fields || [];
      genClearError();
      genModalTitle.textContent = opts.title || '';
      if (opts.message) { genModalMessage.textContent = opts.message; genModalMessage.style.display = 'block'; }
      else { genModalMessage.style.display = 'none'; }
      genModalFields.innerHTML = genFieldsConfig.map(function(f, i){
        var id = 'genField_' + i;
        f._id = id;
        var labelHtml = '<label for="' + id + '">' + esc(f.label || '') + (f.required ? ' *' : '') + '</label>';
        var inputHtml;
        if (f.type === 'textarea') {
          inputHtml = '<textarea id="' + id + '" placeholder="' + esc(f.placeholder || '') + '">' + esc(f.default || '') + '</textarea>';
        } else if (f.type === 'select') {
          inputHtml = '<select id="' + id + '">' + (f.options || []).map(function(o){
            return '<option value="' + esc(o.value) + '"' + (o.value === f.default ? ' selected' : '') + '>' + esc(o.label) + '</option>';
          }).join('') + '</select>';
        } else {
          inputHtml = '<input type="text" id="' + id + '" placeholder="' + esc(f.placeholder || '') + '" value="' + esc(f.default || '') + '">';
        }
        return '<div class="gen-field">' + labelHtml + inputHtml + '</div>';
      }).join('');
      genModalOkBtn.textContent = opts.okLabel || 'OK';
      genModalOkBtn.className = 'vis-btn vis-btn-sm ' + (opts.danger ? 'vis-btn-danger' : 'vis-btn-primary');
      genModalCancelBtn.style.display = (mode === 'alert') ? 'none' : 'inline-block';
      genModalCancelBtn.textContent = opts.cancelLabel || 'Cancel';
      genModal.style.display = 'flex';
      setTimeout(function(){
        var first = genModalFields.querySelector('input,select,textarea');
        (first || genModalOkBtn).focus();
      }, 30);
    });
  }
  genModalOkBtn.addEventListener('click', function(){
    if (genMode === 'confirm') { genFinish(true); return; }
    if (genMode === 'alert') { genFinish(undefined); return; }
    var values = {};
    for (var i = 0; i < genFieldsConfig.length; i++) {
      var f = genFieldsConfig[i];
      var el = document.getElementById(f._id);
      var val = el ? el.value.trim() : '';
      if (f.required && !val) { genShowError('Please fill in "' + f.label + '".'); if (el) el.focus(); return; }
      values[f.name] = val;
    }
    genFinish(values);
  });
  function genCancel() { genFinish(genMode === 'confirm' ? false : (genMode === 'alert' ? undefined : null)); }
  genModalCancelBtn.addEventListener('click', genCancel);
  genModalCloseBtn.addEventListener('click', genCancel);
  /**
   * UI hardening (2026-08-10): a genuine rapid double-click on the button
   * that OPENS this modal (e.g. "Deny") could land its second click on the
   * modal's own backdrop — the overlay appears synchronously with the
   * first click, covering the screen, and the row button's on-screen
   * position rarely coincides with the centered modal card. Without this
   * guard that second click would silently self-cancel the modal the
   * operator just opened, with no error and no obvious explanation why
   * their reason prompt vanished. Genuine outside-clicks (a deliberate
   * dismiss after the modal has been open for a moment) are unaffected —
   * this only suppresses a backdrop click landing within the same instant
   * the modal appeared.
   */
  genModal.addEventListener('click', function(e){ if (e.target === genModal && Date.now() - genOpenedAt > 350) genCancel(); });
  genModalFields.addEventListener('keydown', function(e){
    if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA') { e.preventDefault(); genModalOkBtn.click(); }
  });

  function askText(opts) { return genOpen('prompt', opts); }
  function askConfirm(opts) { return genOpen('confirm', Object.assign({fields: []}, opts)); }
  function showAlert(opts) {
    if (typeof opts === 'string') opts = {message: opts};
    return genOpen('alert', Object.assign({fields: [], okLabel: 'OK', title: 'Notice'}, opts));
  }

  var STATUS_LABEL = {
    pending_approval: ['Pending approval', 'vis-badge-amber'],
    checked_in:       ['On campus', 'vis-badge-green'],
    checked_out:      ['Checked out', 'vis-badge-grey'],
    denied:           ['Denied', 'vis-badge-red'],
    overstay:         ['Overstay', 'vis-badge-red'],
  };
  function statusChip(s) {
    var v = STATUS_LABEL[s] || [s, 'vis-badge-grey'];
    return '<span class="vis-badge ' + v[1] + '">' + esc(v[0]) + '</span>';
  }
  function fmtTime(iso) {
    if (!iso) return '—';
    var d = new Date(iso);
    if (isNaN(d.getTime())) return esc(iso);
    return d.toLocaleString();
  }

  /** Themed loader (window.ZXLoader, wired into header.php) covering a table wrap while it fetches. */
  function withCover(wrapId, message, task) {
    var handle = (window.ZXLoader ? window.ZXLoader.cover('#' + wrapId, message) : {destroy: function(){}});
    return task().finally(function(){ handle.destroy(); });
  }

  // ── Tabs ──────────────────────────────────────────────────────────
  document.querySelectorAll('.vis-tab').forEach(function(tab){
    tab.addEventListener('click', function(){
      document.querySelectorAll('.vis-tab').forEach(function(t){ t.classList.remove('active'); });
      tab.classList.add('active');
      var panel = tab.dataset.panel;
      document.querySelectorAll('.vis-panel').forEach(function(p){ p.classList.remove('active'); });
      document.getElementById(panel).classList.add('active');
      // Navigating away with the camera still live left the stream running
      // (and the camera light on) with nothing visible using it — close it
      // the moment the operator leaves this tab, not just on submit. Only
      // closes an actually-LIVE stream — an already-captured photo (blob
      // sitting ready, camera already off) is left untouched.
      if (panel !== 'panelCheckin' && typeof ciPhotoCloseIfLive === 'function') ciPhotoCloseIfLive();
      if (panel === 'panelDesk') visRefreshDesk();
      if (panel === 'panelLog') visRefreshLog();
      if (panel === 'panelWatch') visRefreshWatchlist();
      if (panel === 'panelGatepass') visRefreshGatePasses();
      if (panel === 'panelMuster') visRefreshMuster();
    });
  });

  // ── Front desk ────────────────────────────────────────────────────
  function actionButtons(row) {
    if (!CAN_EDIT) return '';
    var btns = [];
    if (row.status === 'pending_approval') {
      btns.push('<button class="vis-btn vis-btn-sm vis-btn-primary" data-busy-label="Approving…" onclick="visApprove(this,\'' + row.id + '\')">Approve</button>');
      btns.push('<button class="vis-btn vis-btn-sm vis-btn-danger" data-busy-label="Denying…" onclick="visDeny(this,\'' + row.id + '\')">Deny</button>');
    }
    if (row.status === 'denied' && row.watchlistSeverity === 'block') {
      btns.push('<button class="vis-btn vis-btn-sm vis-btn-danger" data-busy-label="Overriding…" onclick="visOverride(this,\'' + row.id + '\')">Override block</button>');
    }
    if (row.status === 'checked_in' || row.status === 'overstay') {
      btns.push('<a class="vis-btn vis-btn-sm" href="' + BASE + 'campus_access/badge/' + row.id + '" target="_blank">Badge</a>');
      btns.push('<button class="vis-btn vis-btn-sm" data-busy-label="Checking out…" onclick="visCheckout(this,\'' + row.id + '\')">Check out</button>');
    }
    return btns.join(' ');
  }

  /**
   * UI hardening (2026-08-10): the refresh icon-buttons (Desk/GatePass/
   * Muster) previously took no `btn` argument and had no busy-state of
   * their own — the table got a skeleton+ZXLoader cover, but the button
   * itself stayed fully clickable, so a rapid double-click fired two
   * overlapping requests with no visible sign anything was even happening
   * at the button. `btn` is now optional (withBusy's own `if (!btn) return
   * task()` fallback already handles every OTHER call site that invokes
   * this with no button — tab-switch, post-submit, page-load init).
   */
  window.visRefreshDesk = function(btn) {
    document.getElementById('deskRows').innerHTML = skeletonRows([120, 90, 80, 70, 90, 100]);
    return withBusy(btn, function(){
      return withCover('deskTableWrap', 'Loading the gate log…', function(){
        return get(BASE + 'campus_access/list_on_campus').then(function(res){
          var items = (res && res.items) || [];
          document.getElementById('statTotal').textContent = items.length;
          document.getElementById('statOnCampus').textContent = items.filter(function(r){ return r.status === 'checked_in'; }).length;
          document.getElementById('statPending').textContent = items.filter(function(r){ return r.status === 'pending_approval'; }).length;
          document.getElementById('statOverstay').textContent = items.filter(function(r){ return r.status === 'overstay'; }).length;
          document.getElementById('deskRows').innerHTML = items.length
            ? items.map(function(r){
                return '<tr><td>' + avatarCell(r.visitorNameSnapshot, r.visitorPhotoUrl) + '</td><td>' + purposeLabel(r) + '</td><td>' + esc(r.hostStaffNameSnapshot || '—') +
                  '</td><td>' + statusChip(r.status) + '</td><td>' + fmtTime(r.checkInAt) + '</td><td>' + actionButtons(r) + '</td></tr>';
              }).join('')
            : '<tr><td colspan="6" class="vis-empty">No one on campus right now.</td></tr>';
        });
      });
    }).catch(fail);
  };

  window.visApprove = function(btn, id) { withBusy(btn, function(){ return postForm(BASE + 'campus_access/approve_pass/' + id).then(function(){ return visRefreshDesk(); }); }).catch(fail); };
  window.visDeny = function(btn, id) {
    askText({title: 'Deny visitor', fields: [{name: 'reason', label: 'Reason for denial', required: true}], okLabel: 'Deny', danger: true}).then(function(vals){
      if (!vals) return;
      withBusy(btn, function(){ return postForm(BASE + 'campus_access/deny_pass/' + id, {reason: vals.reason}).then(function(){ return visRefreshDesk(); }); }).catch(fail);
    });
  };
  window.visOverride = function(btn, id) {
    askText({title: 'Override watchlist block', fields: [{name: 'reason', label: 'Reason for overriding this block', required: true}], okLabel: 'Override', danger: true}).then(function(vals){
      if (!vals) return;
      withBusy(btn, function(){ return postForm(BASE + 'campus_access/override_block/' + id, {reason: vals.reason}).then(function(){ return visRefreshDesk(); }); }).catch(fail);
    });
  };
  window.visCheckout = function(btn, id) {
    withBusy(btn, function(){ return postForm(BASE + 'campus_access/checkout_pass/' + id, {verificationMethod: 'manual'}); }).then(function(res){
      toast('✓ ' + ((res.pass && res.pass.visitorNameSnapshot) || 'Visitor') + ' checked out.');
      return visRefreshDesk();
    }).catch(fail);
  };

  /**
   * F-03 (2026-08-09 correction pass): the QR only LOOKS UP the pass and
   * cryptographically verifies the token — it never checks anyone out by
   * itself. The operator must visually confirm the returned name/photo
   * matches the person in front of them, THEN take the separate "check out"
   * confirm click. checkoutPass() re-verifies the token server-side too, so
   * this two-step UI can't be skipped by calling the endpoint directly.
   *
   * 2026-08-10: rebuilt as a proper themed modal (was native prompt()/
   * alert()/confirm() — functional but looked like a browser demo, not a
   * product, and gave the operator zero visual identity to confirm against
   * besides typed text). Camera scan uses the browser's native
   * BarcodeDetector (Chrome/Edge desktop+Android) — no third-party decoder
   * bundled, consistent with this codebase's no-external-QR-dependency
   * stance (see qr_token_helper.php's comment on local-only QR rendering).
   * Where BarcodeDetector isn't available, the modal opens straight to the
   * paste field instead — same lookup-then-confirm flow either way, still
   * inside the modal, never a native prompt.
   */
  var qrScanModal = document.getElementById('qrScanModal');
  var qrModalError = document.getElementById('qrModalError');
  var qrStepScan = document.getElementById('qrStepScan');
  var qrStepConfirm = document.getElementById('qrStepConfirm');
  var qrCameraBox = document.getElementById('qrCameraBox');
  var qrScanVideo = document.getElementById('qrScanVideo');
  var qrCameraHint = document.getElementById('qrCameraHint');
  var qrPasteRow = document.getElementById('qrPasteRow');
  var qrPasteInput = document.getElementById('qrPasteInput');
  var qrPasteLookupBtn = document.getElementById('qrPasteLookupBtn');
  var qrTogglePasteBtn = document.getElementById('qrTogglePasteBtn');
  var qrConfirmAvatar = document.getElementById('qrConfirmAvatar');
  var qrConfirmName = document.getElementById('qrConfirmName');
  var qrConfirmMeta = document.getElementById('qrConfirmMeta');
  var qrConfirmBackBtn = document.getElementById('qrConfirmBackBtn');
  var qrConfirmOkBtn = document.getElementById('qrConfirmOkBtn');
  var qrModalCloseBtn = document.getElementById('qrModalCloseBtn');

  var qrScanStream = null;
  var qrScanRAF = null;
  var qrScanDetector = null;
  if (window.BarcodeDetector) {
    try { qrScanDetector = new BarcodeDetector({formats: ['qr_code']}); } catch (e) { qrScanDetector = null; }
  }
  var qrPendingToken = null;
  var qrPendingPassId = null;

  function qrShowError(msg) { qrModalError.textContent = msg; qrModalError.style.display = 'block'; }
  function qrClearError() { qrModalError.style.display = 'none'; qrModalError.textContent = ''; }
  function qrStopCamera() {
    if (qrScanRAF) { cancelAnimationFrame(qrScanRAF); qrScanRAF = null; }
    if (qrScanStream) { qrScanStream.getTracks().forEach(function(t){ t.stop(); }); qrScanStream = null; }
  }
  function qrCloseModal() {
    qrStopCamera();
    qrScanModal.style.display = 'none';
    qrClearError();
    qrPasteInput.value = '';
  }
  function qrShowScanStep() {
    qrStepConfirm.style.display = 'none';
    qrStepScan.style.display = 'block';
    qrClearError();
  }
  function qrShowConfirmStep(pass, passId, token) {
    qrPendingToken = token;
    qrPendingPassId = passId;
    qrStopCamera();
    qrStepScan.style.display = 'none';
    qrStepConfirm.style.display = 'block';
    qrClearError();
    var name = pass.visitorNameSnapshot || 'Visitor';
    qrConfirmName.textContent = name;
    qrConfirmMeta.textContent = (pass.purpose ? pass.purpose + ' · ' : '') + 'Pass ' + passId;
    qrConfirmAvatar.innerHTML = pass.visitorPhotoUrl
      ? '<img src="' + esc(pass.visitorPhotoUrl) + '" alt="">'
      : '<span>' + esc(initials(name)) + '</span>';
  }
  /** btn is the clicked "Look up" button when called from a manual paste — omitted (no busy state to show) when called from the camera's own auto-detect tick. */
  function qrLookup(token, btn) {
    qrClearError();
    withBusy(btn, function(){ return postForm(BASE + 'campus_access/scan_qr_lookup', {token: token}); }).then(function(res){
      if (!res || !res.pass) { qrShowError('QR did not resolve to a pass.'); return; }
      if (!res.canCheckout) {
        qrShowError('This pass is not currently checkoutable (status: ' + (res.pass.status || 'unknown') + ').');
        return;
      }
      qrShowConfirmStep(res.pass, res.passId, token);
    }).catch(function(err){ qrShowError((err && err.message) || 'Failed to look up the QR code.'); });
  }
  function qrTogglePaste(showPaste) {
    if (showPaste) {
      qrStopCamera();
      qrCameraBox.style.display = 'none';
      qrCameraHint.style.display = 'none'; // was left showing "Point the camera…" underneath the paste field
      qrPasteRow.style.display = 'flex';
      qrTogglePasteBtn.style.display = qrScanDetector ? 'block' : 'none';
      qrTogglePasteBtn.textContent = 'Use camera instead';
      qrPasteInput.focus();
    } else {
      qrPasteRow.style.display = 'none';
      qrTogglePasteBtn.textContent = 'Paste the code instead';
      qrOpenCamera();
    }
  }
  function qrOpenCamera() {
    qrClearError();
    // BUG-057 fix (2026-08-10): BarcodeDetector can exist without a secure
    // context (unlike getUserMedia), so on an insecure/unsupported host
    // (plain HTTP on a school LAN, not localhost/HTTPS) navigator.mediaDevices
    // itself can be undefined — calling .getUserMedia on it threw a
    // synchronous TypeError before the promise chain existed, so the
    // trailing .catch() below never ran and the documented paste fallback
    // was never reached. Guard the same way ciOpenCamera() already does.
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      // qrTogglePaste(true) hides qrCameraHint itself, so a message set here
      // would never be seen — use a toast instead, matching ciOpenCamera()'s
      // showAlert() for the same no-support case.
      toast('This browser/context does not support camera scanning — paste the code instead.');
      qrTogglePaste(true);
      return;
    }
    qrCameraBox.style.display = 'block';
    qrCameraHint.style.display = 'block';
    qrCameraHint.textContent = 'Point the camera at the QR on the printed badge.';
    navigator.mediaDevices.getUserMedia({video: {facingMode: {ideal: 'environment'}}, audio: false}).then(function(stream){
      qrScanStream = stream;
      qrScanVideo.srcObject = stream;
      var tick = function() {
        if (!qrScanStream) return; // modal was closed / switched to paste mid-flight
        qrScanDetector.detect(qrScanVideo).then(function(codes){
          if (codes && codes.length && codes[0].rawValue) {
            qrStopCamera();
            qrLookup(codes[0].rawValue);
          } else {
            qrScanRAF = requestAnimationFrame(tick);
          }
        }).catch(function(){ qrScanRAF = requestAnimationFrame(tick); });
      };
      qrScanRAF = requestAnimationFrame(tick);
    }).catch(function(){
      qrCameraHint.textContent = 'Could not access the camera — check browser permissions, or paste the code below.';
      qrTogglePaste(true);
    });
  }

  qrTogglePasteBtn.addEventListener('click', function(){
    qrTogglePaste(qrPasteRow.style.display !== 'flex');
  });
  qrPasteLookupBtn.addEventListener('click', function(){
    var token = qrPasteInput.value.trim();
    if (!token) { qrShowError('Enter or paste a QR token first.'); return; }
    qrLookup(token, qrPasteLookupBtn);
  });
  qrPasteInput.addEventListener('keydown', function(e){ if (e.key === 'Enter') { e.preventDefault(); qrPasteLookupBtn.click(); } });
  qrConfirmBackBtn.addEventListener('click', function(){
    qrShowScanStep();
    if (qrScanDetector && qrPasteRow.style.display !== 'flex') qrOpenCamera(); else qrTogglePaste(true);
  });
  qrConfirmOkBtn.addEventListener('click', function(){
    withBusy(qrConfirmOkBtn, function(){
      return postForm(BASE + 'campus_access/checkout_pass/' + qrPendingPassId, {verificationMethod: 'qr_manual', token: qrPendingToken});
    }).then(function(res){
      var name = (res.pass && res.pass.visitorNameSnapshot) || 'Visitor';
      qrCloseModal();
      toast('✓ ' + name + ' checked out.');
      visRefreshDesk();
    }).catch(function(err){ qrShowError((err && err.message) || 'Checkout failed.'); });
  });
  qrModalCloseBtn.addEventListener('click', qrCloseModal);
  // UI hardening (2026-08-10): same rapid-double-click backdrop self-cancel
  // guard as genModal — see that handler's comment for the full reasoning.
  var qrOpenedAt = 0;
  qrScanModal.addEventListener('click', function(e){ if (e.target === qrScanModal && Date.now() - qrOpenedAt > 350) qrCloseModal(); });

  window.visScanCheckout = function() {
    qrOpenedAt = Date.now();
    qrScanModal.style.display = 'flex';
    qrShowScanStep();
    qrPasteRow.style.display = 'none';
    if (qrScanDetector) { qrOpenCamera(); } else { qrTogglePaste(true); }
  };

  // ── Check-in form ─────────────────────────────────────────────────

  // Accompanying-visitors stepper
  var ciAccN = document.getElementById('ciAccN');
  var ciAccInput = document.getElementById('ciAccInput');
  var ciAccVal = 0;
  function ciAccRender() { ciAccN.textContent = ciAccVal; ciAccInput.value = ciAccVal; }
  document.getElementById('ciAccMinus').addEventListener('click', function(){ ciAccVal = Math.max(0, ciAccVal - 1); ciAccRender(); });
  document.getElementById('ciAccPlus').addEventListener('click', function(){ ciAccVal = ciAccVal + 1; ciAccRender(); });

  // Live watchlist preview as the desk types — server re-checks authoritatively
  // at submit time regardless; this is a heads-up, not the enforcement point.
  var ciWatchFlag = document.getElementById('ciWatchFlag');
  var runWatchCheck = (window.ZXutil ? window.ZXutil.debounce : function(fn){ return fn; })(function(){
    var name = document.getElementById('ciName').value.trim();
    var phone = document.getElementById('ciPhone').value.trim();
    if (name.length < 3 && phone.length < 5) { ciWatchFlag.style.display = 'none'; return; }
    get(BASE + 'campus_access/watchlist_check', {name: name, phone: phone}).then(function(res){
      // UI hardening (2026-08-10): stale-response guard — if the operator
      // kept typing after this request fired, a slower earlier response
      // must not overwrite/clear a newer, more relevant flag state.
      if (document.getElementById('ciName').value.trim() !== name || document.getElementById('ciPhone').value.trim() !== phone) return;
      var m = res && res.match;
      if (!m) { ciWatchFlag.style.display = 'none'; return; }
      // BUG-056 fix: 'reason' is now only sent to manage-grade holders — a
      // view-grade front-desk operator still gets the severity warning
      // (so they can deny/escalate) without the safeguarding detail text.
      ciWatchFlag.textContent = '⚑ Watchlist match (' + (m.severity === 'block' ? 'block' : 'flag for review') + ')'
        + (m.reason ? ' — ' + m.reason : ' — ask a manage-level staff member for details');
      ciWatchFlag.style.display = 'block';
    }).catch(function(){ ciWatchFlag.style.display = 'none'; });
  }, 300);
  document.getElementById('ciName').addEventListener('input', runWatchCheck);
  document.getElementById('ciPhone').addEventListener('input', runWatchCheck);

  // ID proof: per-type format hints/pattern, mirroring the server-side
  // structural checks (Visitor_service::_validateIdProof) so a front-desk
  // typo gets caught before submit, not just rejected after. The server
  // check is authoritative either way — this is UX, not the security gate.
  var CI_ID_PROOF_RULES = {
    'Aadhaar':          {pattern: '[0-9]{12}',            hint: '12 digits, e.g. 234123412346', placeholder: '12-digit Aadhaar number'},
    'PAN':               {pattern: '[A-Za-z]{5}[0-9]{4}[A-Za-z]', hint: 'Format: ABCDE1234F', placeholder: 'ABCDE1234F'},
    'Voter ID':          {pattern: '[A-Za-z]{3}[0-9]{7}',  hint: 'Format: ABC1234567 (EPIC)', placeholder: 'ABC1234567'},
    'Passport':          {pattern: '[A-Za-z][0-9]{7}',     hint: '1 letter + 7 digits, e.g. A1234567', placeholder: 'A1234567'},
    'Driving Licence':   {pattern: '[A-Za-z0-9 -]{10,16}', hint: 'State DL number, 10-16 characters', placeholder: 'e.g. MH1220110012345'},
    'Other':             {pattern: '.{3,}',                hint: 'At least 3 characters', placeholder: 'ID proof number'},
  };
  var ciIdProofType = document.getElementById('ciIdProofType');
  var ciIdProofNumber = document.getElementById('ciIdProofNumber');
  var ciIdProofHint = document.getElementById('ciIdProofHint');
  ciIdProofType.addEventListener('change', function(){
    var rule = CI_ID_PROOF_RULES[ciIdProofType.value];
    if (!rule) {
      ciIdProofNumber.value = '';
      ciIdProofNumber.disabled = true;
      ciIdProofNumber.removeAttribute('pattern');
      ciIdProofNumber.placeholder = 'Select a type first';
      ciIdProofHint.textContent = 'Stored as last 4 characters only — the full number is never kept as text.';
      return;
    }
    ciIdProofNumber.disabled = false;
    ciIdProofNumber.setAttribute('pattern', rule.pattern);
    ciIdProofNumber.placeholder = rule.placeholder;
    ciIdProofHint.textContent = rule.hint + ' — stored as last 4 characters only.';
  });

  // "Other" purpose — purposeNote was already wired end-to-end server-side
  // (Visitor_service::createPass stores it, the printed badge/log could show
  // it) but the form never had an input for it, so "Other" always saved with
  // no detail at all. Required when shown — "Other" alone isn't useful for
  // a security log.
  var ciPurpose = document.getElementById('ciPurpose');
  var ciPurposeNoteWrap = document.getElementById('ciPurposeNoteWrap');
  var ciPurposeNote = document.getElementById('ciPurposeNote');
  ciPurpose.addEventListener('change', function(){
    var isOther = ciPurpose.value === 'Other';
    ciPurposeNoteWrap.style.display = isOther ? 'block' : 'none';
    ciPurposeNote.required = isOther;
    if (!isOther) ciPurposeNote.value = '';
  });

  var ciHostSearch = document.getElementById('ciHostSearch');
  var ciHostResults = document.getElementById('ciHostResults');
  /**
   * UI hardening (2026-08-10): two gaps fixed together —
   *  (1) no feedback at all between typing and results appearing (silence,
   *      not even a "Searching…" placeholder) — the operator had no way to
   *      tell a slow network call apart from a keystroke that didn't
   *      register.
   *  (2) no stale-response guard — if an earlier query's response arrives
   *      AFTER a later one (plausible: debounce only limits how often a
   *      request FIRES, not how fast each one RESPONDS), the earlier,
   *      now-irrelevant result set could silently overwrite the newer,
   *      correct one. Guarded by comparing the input's CURRENT value
   *      against the value this specific response was fetched for.
   */
  var runHostSearch = (window.ZXutil ? window.ZXutil.debounce : function(fn){ return fn; })(function(q){
    ciHostResults.innerHTML = '<div class="vis-search-item" style="padding:8px 10px;color:var(--t3)">Searching…</div>';
    ciHostResults.style.display = 'block';
    get(BASE + 'campus_access/search_staff', {q: q}).then(function(res){
      if (ciHostSearch.value !== q) return; // a newer keystroke already superseded this request — discard the stale response
      var items = (res && res.items) || [];
      if (!items.length) { ciHostResults.style.display = 'none'; return; }
      ciHostResults.innerHTML = items.map(function(s){
        return '<div class="vis-search-item" style="padding:8px 10px;cursor:pointer" data-id="' + esc(s.id) + '" data-name="' + esc(s.name) + '">' + esc(s.name) + '</div>';
      }).join('');
      ciHostResults.style.display = 'block';
    }).catch(function(){ if (ciHostSearch.value === q) ciHostResults.style.display = 'none'; });
  }, 250);
  ciHostSearch.addEventListener('input', function(){
    var q = ciHostSearch.value;
    document.getElementById('ciHostId').value = '';
    document.getElementById('ciHostName').value = '';
    if (q.length < 2) { ciHostResults.style.display = 'none'; return; }
    runHostSearch(q);
  });
  ciHostResults.addEventListener('click', function(e){
    var item = e.target.closest('.vis-search-item');
    if (!item) return;
    document.getElementById('ciHostId').value = item.dataset.id;
    document.getElementById('ciHostName').value = item.dataset.name;
    ciHostSearch.value = item.dataset.name;
    ciHostResults.style.display = 'none';
  });

  // ── Visitor photo capture (getUserMedia, no libraries) ─────────────
  // Engineering notes (2026-08-09):
  //  - Requests a high-resolution stream (ideal 1280x960) instead of
  //    whatever low default the browser picks, so the source frame is
  //    actually sharp before it's cropped.
  //  - Exports a 480x480 JPEG at quality 0.92 (was 224x224 @0.9) — a
  //    visibly sharper badge photo without ballooning upload size.
  //  - A live oval guide (govt ID-capture style — DigiLocker/UIDAI selfie
  //    flows use the same pattern) shows the operator where to frame the
  //    face. No face-detection model is bundled (heavy dependency, and the
  //    Shape Detection API's FaceDetector is Chrome-only/unstable) — instead
  //    a lightweight luminance sample of the oval region drives the same
  //    white->green "ready" cue real capture UIs give, purely as a framing/
  //    lighting aid. It never blocks the Capture button.
  var ciPhotoStream = null;
  var ciPhotoBlob = null;
  var ciPhotoVideo = document.getElementById('ciPhotoVideo');
  var ciPhotoImg = document.getElementById('ciPhotoImg');
  var ciPhotoBox = document.getElementById('ciPhotoBox');
  var ciPhotoLiveWrap = document.getElementById('ciPhotoLiveWrap');
  var ciPhotoOval = document.getElementById('ciPhotoOval');
  var ciPhotoHint = document.getElementById('ciPhotoHint');
  var ciPhotoPlaceholder = document.getElementById('ciPhotoPlaceholder');
  var ciPhotoOpenBtn = document.getElementById('ciPhotoOpenBtn');
  var ciPhotoSnapBtn = document.getElementById('ciPhotoSnapBtn');
  var ciPhotoRetakeBtn = document.getElementById('ciPhotoRetakeBtn');
  var ciPhotoCanvas = document.getElementById('ciPhotoCanvas');
  var ciPhotoSampleCanvas = document.createElement('canvas');
  var ciPhotoAssessTimer = null;
  var ciPhotoIdleTimer = null;
  var CI_PHOTO_EXPORT_SIZE = 480;
  var CI_PHOTO_IDLE_MS = 60000; // auto-close the camera after 1 minute of no capture — leaving it live indefinitely keeps the camera light on for no reason

  function ciPhotoStopStream() {
    if (ciPhotoStream) { ciPhotoStream.getTracks().forEach(function(t){ t.stop(); }); ciPhotoStream = null; }
    if (ciPhotoAssessTimer) { clearInterval(ciPhotoAssessTimer); ciPhotoAssessTimer = null; }
    if (ciPhotoIdleTimer) { clearTimeout(ciPhotoIdleTimer); ciPhotoIdleTimer = null; }
  }

  /** Stops the stream AND resets the UI to the placeholder — used whenever the live camera is abandoned (idle timeout, navigating to another tab) rather than deliberately captured. A no-op if a photo was already captured (camera's already off; the blob is left alone). */
  function ciPhotoCloseIfLive() {
    if (!ciPhotoStream) return;
    ciPhotoStopStream();
    ciPhotoLiveWrap.style.display = 'none';
    ciPhotoBox.style.display = 'flex';
    ciPhotoImg.style.display = 'none';
    ciPhotoPlaceholder.style.display = 'flex';
    ciPhotoOpenBtn.style.display = 'inline-block';
    ciPhotoSnapBtn.style.display = 'none';
    ciPhotoRetakeBtn.style.display = 'none';
  }

  /** Samples the oval region's average brightness to drive the white/green ready cue — a framing+lighting aid, never a hard gate on Capture. */
  function ciPhotoAssessFrame() {
    var vw = ciPhotoVideo.videoWidth, vh = ciPhotoVideo.videoHeight;
    if (!vw || !vh) return;
    var sw = 48, sh = 48;
    var sx = vw / 2 - sw / 2, sy = vh * 0.44 - sh / 2;
    ciPhotoSampleCanvas.width = sw; ciPhotoSampleCanvas.height = sh;
    var sctx = ciPhotoSampleCanvas.getContext('2d');
    try { sctx.drawImage(ciPhotoVideo, sx, sy, sw, sh, 0, 0, sw, sh); } catch (e) { return; }
    var data;
    try { data = sctx.getImageData(0, 0, sw, sh).data; } catch (e) { return; }
    var total = 0, n = data.length / 4;
    for (var i = 0; i < data.length; i += 4) { total += data[i] * 0.299 + data[i + 1] * 0.587 + data[i + 2] * 0.114; }
    var avg = total / n;
    var ready = avg > 55 && avg < 235;
    ciPhotoOval.classList.toggle('ready', ready);
    ciPhotoHint.textContent = ready
      ? 'Looking good — Capture when ready'
      : (avg <= 55 ? 'Too dark — move to better light' : 'Too bright — reduce glare/backlight');
  }

  /**
   * Shared by the initial "Open camera" click AND "Retake" — Retake used to
   * just reset back to the placeholder + a fresh "Open camera" button,
   * requiring an extra click to actually restart the camera.
   *
   * UI hardening (2026-08-10): getUserMedia() can take a real, visible
   * moment — the FIRST time especially, while the browser shows its own
   * permission prompt, which can sit open for as long as the operator
   * takes to respond. Previously the Open/Retake button showed no busy
   * state at all during that window, so a second click while the prompt
   * was pending called getUserMedia() again concurrently: the second
   * stream's tracks would overwrite `ciPhotoStream`, orphaning the first
   * stream's tracks running (and un-stopped) in the background — a real
   * camera-stays-on resource leak, not just a cosmetic issue. `btn` (the
   * clicked button, when known) now gets the same withBusy() treatment
   * every other async action in this module uses; the no-support early
   * exit stays outside withBusy since it's synchronous and already shows
   * its own alert immediately.
   */
  function ciOpenCamera(btn) {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      ciPhotoLiveWrap.style.display = 'none';
      ciPhotoBox.style.display = 'flex';
      ciPhotoImg.style.display = 'none';
      ciPhotoPlaceholder.style.display = 'flex';
      ciPhotoOpenBtn.style.display = 'inline-block';
      ciPhotoSnapBtn.style.display = 'none';
      ciPhotoRetakeBtn.style.display = 'none';
      showAlert('This browser/context does not support camera capture (camera access needs HTTPS or localhost). You can still check the visitor in without a photo.');
      return;
    }
    withBusy(btn, function(){
      return navigator.mediaDevices.getUserMedia({
        video: {facingMode: 'user', width: {ideal: 1280}, height: {ideal: 960}},
        audio: false,
      }).then(function(stream){
        ciPhotoStream = stream;
        ciPhotoVideo.srcObject = stream;
        ciPhotoBox.style.display = 'none';
        ciPhotoLiveWrap.style.display = 'block';
        ciPhotoOpenBtn.style.display = 'none';
        ciPhotoSnapBtn.style.display = 'inline-block';
        ciPhotoRetakeBtn.style.display = 'none';
        ciPhotoBlob = null;
        ciPhotoOval.classList.remove('ready');
        ciPhotoHint.textContent = 'Center your face in the oval, then Capture';
        ciPhotoAssessTimer = setInterval(ciPhotoAssessFrame, 350);
        // Auto-close if left running with no capture — otherwise the camera
        // (and its hardware indicator light) stays on indefinitely for no
        // reason once the operator gets distracted mid check-in.
        ciPhotoIdleTimer = setTimeout(function(){
          ciPhotoCloseIfLive();
          toast('Camera closed — no photo taken within 1 minute.');
        }, CI_PHOTO_IDLE_MS);
      }, function(){
        // Always land back on a working placeholder + "Open camera" button —
        // without this, a failure during Retake (permission revoked mid-
        // session, camera now busy elsewhere) left the box showing neither
        // the old photo nor the live feed, with no visible way to retry.
        // Two-arg .then(), not .catch() — a camera-access failure resolves
        // this promise (with an alert shown), it never rejects, so withBusy
        // always restores the button cleanly either way.
        ciPhotoLiveWrap.style.display = 'none';
        ciPhotoBox.style.display = 'flex';
        ciPhotoImg.style.display = 'none';
        ciPhotoPlaceholder.style.display = 'flex';
        ciPhotoOpenBtn.style.display = 'inline-block';
        ciPhotoSnapBtn.style.display = 'none';
        ciPhotoRetakeBtn.style.display = 'none';
        showAlert('Could not access the camera — check browser permissions.');
      });
    });
  }
  ciPhotoOpenBtn.addEventListener('click', function(){ ciOpenCamera(ciPhotoOpenBtn); });

  ciPhotoSnapBtn.addEventListener('click', function(){
    withBusy(ciPhotoSnapBtn, function(){
      return new Promise(function(resolve){
        var size = CI_PHOTO_EXPORT_SIZE;
        ciPhotoCanvas.width = size; ciPhotoCanvas.height = size;
        var ctx = ciPhotoCanvas.getContext('2d');
        ctx.imageSmoothingEnabled = true;
        ctx.imageSmoothingQuality = 'high';
        var vw = ciPhotoVideo.videoWidth || size, vh = ciPhotoVideo.videoHeight || size;
        var side = Math.min(vw, vh);
        // Center-crop to a square — matches every consumer of this photo
        // elsewhere in the app (circular table avatars, the badge image), all
        // of which render it via object-fit:cover on a square box.
        ctx.drawImage(ciPhotoVideo, (vw - side) / 2, (vh - side) / 2, side, side, 0, 0, size, size);
        ciPhotoCanvas.toBlob(function(blob){
          ciPhotoBlob = blob;
          ciPhotoImg.src = URL.createObjectURL(blob);
          ciPhotoImg.style.display = 'block';
          ciPhotoPlaceholder.style.display = 'none';
          ciPhotoLiveWrap.style.display = 'none';
          ciPhotoBox.style.display = 'flex';
          ciPhotoStopStream();
          ciPhotoSnapBtn.style.display = 'none';
          ciPhotoRetakeBtn.style.display = 'inline-block';
          resolve();
        }, 'image/jpeg', 0.92);
      });
    });
  });

  ciPhotoRetakeBtn.addEventListener('click', function(){
    ciPhotoBlob = null;
    ciPhotoImg.style.display = 'none';
    ciOpenCamera(ciPhotoRetakeBtn); // instant re-open, not just a reset back to the placeholder
  });

  /** Multipart POST — the global fetch patch only string-appends CSRF, so a
   *  FormData body needs it appended by hand (same csrfName/csrfToken
   *  globals header.php already defines). */
  function postMultipart(url, formData) {
    formData.append(csrfName, csrfToken);
    return fetch(url, {method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: formData})
      .then(function(r){
        return r.json().then(function(j){
          if (!r.ok || (j && j.status === 'error')) throw new Error((j && j.message) || 'Something went wrong.');
          return j;
        });
      });
  }

  document.getElementById('ciForm').addEventListener('submit', function(e){
    e.preventDefault();
    var ciSubmitBtn = e.target.querySelector('button[type=submit]');
    var submitPromise;
    if (ciPhotoBlob) {
      var fd = new FormData(e.target);
      fd.append('photo', ciPhotoBlob, 'visitor.jpg');
      submitPromise = postMultipart(BASE + 'campus_access/create_pass', fd);
    } else {
      var params = new URLSearchParams(new FormData(e.target));
      submitPromise = postForm(BASE + 'campus_access/create_pass', params);
    }
    withBusy(ciSubmitBtn, function(){ return submitPromise; }).then(function(res){
      var pass = res.pass || {};
      if (pass.status === 'denied') {
        showAlert({title: 'Check-in denied', message: pass.deniedReason || 'watchlist block', danger: true});
      } else if (pass.status === 'pending_approval') {
        showAlert({title: 'Held for review', message: 'Visitor logged — flagged for review, held at Front Desk pending approval.'});
      } else {
        // window.open() fires synchronously, in the same tick as the click —
        // deferring it behind the modal's own Promise risked the popup
        // blocker treating it as not directly user-triggered.
        window.open(BASE + 'campus_access/badge/' + pass.id, '_blank');
        toast('✓ Checked in. Badge opened in a new tab.');
      }
      e.target.reset();
      ciPhotoStopStream();
      ciPhotoBlob = null;
      ciPhotoImg.style.display = 'none';
      ciPhotoLiveWrap.style.display = 'none';
      ciPhotoBox.style.display = 'flex';
      ciPhotoPlaceholder.style.display = 'flex';
      ciPhotoOpenBtn.style.display = 'inline-block';
      ciPhotoSnapBtn.style.display = 'none';
      ciPhotoRetakeBtn.style.display = 'none';
      ciAccVal = 0; ciAccRender();
      ciWatchFlag.style.display = 'none';
      ciIdProofType.dispatchEvent(new Event('change')); // form.reset() clears the <select> but not the disabled/pattern state I set by hand
      ciPurpose.dispatchEvent(new Event('change')); // same — hides/clears the "Other" note box
      document.querySelector('.vis-tab[data-panel=panelDesk]').click();
    }).catch(fail);
  });

  // ── Gate Pass (student/staff — separate policy) ─────────────────────
  /**
   * UI hardening (2026-08-10): same two fixes as ciHostSearch above —
   * a "Searching…" placeholder while the request is in flight (previously
   * nothing visible changed between typing and results appearing), and a
   * stale-response guard (compare searchEl's CURRENT value against the
   * value this response was fetched for before rendering it) so a slower
   * earlier response can't overwrite a faster later one. Shared by all 3
   * uses of this helper (gp-person, gp-teacher, le-person).
   */
  function wirePersonSearch(typeSelectEl, searchEl, resultsEl, idFieldEl, nameFieldEl) {
    // nameFieldEl is optional (null) for a lookup that only needs the id,
    // e.g. the teacher search below — passing the SAME element for both id
    // and name would overwrite the id with the name string.
    var timer = null;
    searchEl.addEventListener('input', function(){
      var q = searchEl.value;
      idFieldEl.value = '';
      if (nameFieldEl) nameFieldEl.value = '';
      clearTimeout(timer);
      if (q.length < 2) { resultsEl.style.display = 'none'; return; }
      timer = setTimeout(function(){
        resultsEl.innerHTML = '<div class="vis-search-item" style="padding:8px 10px;color:var(--t3)">Searching…</div>';
        resultsEl.style.display = 'block';
        var isStudent = !typeSelectEl || typeSelectEl.value === 'student';
        get(BASE + (isStudent ? 'campus_access/search_students' : 'campus_access/search_staff'), {q: q}).then(function(res){
          if (searchEl.value !== q) return; // superseded by a newer keystroke — discard the stale response
          var items = (res && res.items) || [];
          if (!items.length) { resultsEl.style.display = 'none'; return; }
          resultsEl.innerHTML = items.map(function(s){
            var extra = s.className ? (' — ' + s.className + (s.section ? '-' + s.section : '')) : (s.designation ? ' — ' + s.designation : '');
            return '<div class="vis-search-item" style="padding:8px 10px;cursor:pointer" data-id="' + esc(s.id) + '" data-name="' + esc(s.name) + '">' + esc(s.name) + '<span style="color:var(--t3);font-size:11px">' + esc(extra) + '</span></div>';
          }).join('');
          resultsEl.style.display = 'block';
        }).catch(function(){ if (searchEl.value === q) resultsEl.style.display = 'none'; });
      }, 250);
    });
    resultsEl.addEventListener('click', function(e){
      var item = e.target.closest('.vis-search-item');
      if (!item) return;
      idFieldEl.value = item.dataset.id;
      if (nameFieldEl) nameFieldEl.value = item.dataset.name;
      searchEl.value = item.dataset.name;
      resultsEl.style.display = 'none';
    });
  }
  wirePersonSearch(document.getElementById('gpPersonType'), document.getElementById('gpPersonSearch'), document.getElementById('gpPersonResults'), document.getElementById('gpPersonId'), document.getElementById('gpPersonName'));
  wirePersonSearch(null, document.getElementById('gpTeacherSearch'), document.getElementById('gpTeacherResults'), document.getElementById('gpTeacherId'), null); // teacher — id only, no separate name field needed
  wirePersonSearch(document.getElementById('lePersonType'), document.getElementById('lePersonSearch'), document.getElementById('lePersonResults'), document.getElementById('lePersonId'), document.getElementById('lePersonName'));

  document.getElementById('gpPersonType').addEventListener('change', function(){
    document.getElementById('gpTeacherRow').style.display = this.value === 'student' ? '' : 'none';
  });

  document.getElementById('gpForm').addEventListener('submit', function(e){
    e.preventDefault();
    var gpBtn = e.target.querySelector('button[type=submit]');
    var params = new URLSearchParams(new FormData(e.target));
    withBusy(gpBtn, function(){ return postForm(BASE + 'campus_access/create_gate_pass', params); }).then(function(){
      e.target.reset();
      document.getElementById('gpTeacherRow').style.display = '';
      toast('✓ Sent for approval.');
      visRefreshGatePasses();
    }).catch(fail);
  });
  document.getElementById('leForm').addEventListener('submit', function(e){
    e.preventDefault();
    var leBtn = e.target.querySelector('button[type=submit]');
    var params = new URLSearchParams(new FormData(e.target));
    withBusy(leBtn, function(){ return postForm(BASE + 'campus_access/log_late_entry', params); }).then(function(){
      e.target.reset();
      toast('✓ Late entry logged.');
      visRefreshGatePasses();
    }).catch(fail);
  });

  var GP_STATUS_LABEL = {
    pending_approval: ['Pending approval', 'vis-badge-amber'],
    approved: ['Approved — awaiting release', 'vis-badge-amber'],
    denied: ['Denied', 'vis-badge-red'],
    released: ['Released', 'vis-badge-green'],
    logged: ['Logged', 'vis-badge-grey'],
  };
  function gpApprovalSummary(approvals) {
    if (!approvals || !Object.keys(approvals).length) return '—';
    return Object.keys(approvals).map(function(role){
      var a = approvals[role];
      var mark = a.status === 'approved' ? '✓' : (a.status === 'denied' ? '✗' : '…');
      return role.charAt(0).toUpperCase() + role.slice(1) + ' ' + mark;
    }).join(' · ');
  }
  /** The board now includes still-open backlog from before today (see Gate_pass_service::listToday) — this makes that visible instead of leaving the operator wondering why an older row is here. */
  function gpAgeHint(updatedAt) {
    if (!updatedAt) return '';
    var d = new Date(updatedAt);
    if (isNaN(d.getTime())) return '';
    var today = new Date();
    var isToday = d.getFullYear() === today.getFullYear() && d.getMonth() === today.getMonth() && d.getDate() === today.getDate();
    if (isToday) return '';
    var days = Math.max(1, Math.round((today - d) / 86400000));
    return ' <span style="color:var(--t3);font-size:11px">· ' + days + (days === 1 ? ' day ago' : ' days ago') + '</span>';
  }
  function gpActionButtons(r) {
    if (!CAN_EDIT) return '';
    var btns = [];
    if (r.status === 'pending_approval' && r.approvals) {
      Object.keys(r.approvals).forEach(function(role){
        if (r.approvals[role].status === 'pending') {
          btns.push('<button class="vis-btn vis-btn-sm vis-btn-primary" data-busy-label="Approving…" onclick="visGpDecide(this,\'' + r.id + '\',\'' + role + '\',\'approved\')">' + role + ' ✓</button>');
          btns.push('<button class="vis-btn vis-btn-sm vis-btn-danger" data-busy-label="Denying…" onclick="visGpDecide(this,\'' + r.id + '\',\'' + role + '\',\'denied\')">' + role + ' ✗</button>');
        }
      });
    }
    if (r.status === 'approved') {
      btns.push('<button class="vis-btn vis-btn-sm vis-btn-primary" data-busy-label="Releasing…" onclick="visGpRelease(this,\'' + r.id + '\')">Confirm release</button>');
    }
    return btns.join(' ');
  }
  /**
   * F-01 (2026-08-09 correction pass): a parent 'approved' decision now
   * requires guardian identity so the record shows WHO GAVE consent
   * (guardian) distinct from WHO RECORDED it (the acting front-desk/admin
   * account, captured server-side). A teacher decision from an account that
   * isn't the request's bound class teacher is rejected server-side with a
   * delegate-reason prompt — this is a genuine identity-binding check, not
   * a UI nicety, so the retry-with-reason path below is just surfacing that
   * server rejection, never bypassing it.
   */
  window.visGpDecide = function(btn, id, role, decision) {
    var isApprovingParent = (role === 'parent' && decision === 'approved');
    var fields = [{name: 'note', label: 'Note (optional)', required: false}];
    if (isApprovingParent) {
      fields.push(
        {name: 'guardianName', label: 'Guardian name (who is giving consent — NOT the staff member recording this)', required: true},
        {name: 'relationship', label: 'Relationship to the student (e.g. Mother, Father, Guardian)', required: true},
        {name: 'consentMethod', label: 'How was consent given', type: 'select', required: true, default: 'phone',
          options: [{value: 'phone', label: 'Phone'}, {value: 'in_person', label: 'In person'}]},
        {name: 'contact', label: 'Contact number used to reach the guardian (optional — stored masked)', required: false}
      );
    }
    askText({
      title: (decision === 'approved' ? 'Approve' : 'Deny') + ' as ' + role,
      fields: fields,
      okLabel: decision === 'approved' ? 'Approve' : 'Deny',
      danger: decision === 'denied',
    }).then(function(vals){
      if (!vals) return;
      var params = {decision: decision, note: vals.note || ''};
      if (isApprovingParent) {
        params.guardianName = vals.guardianName; params.relationship = vals.relationship;
        params.consentMethod = vals.consentMethod; params.contact = vals.contact || '';
      }
      withBusy(btn, function(){ return postForm(BASE + 'campus_access/record_approval/' + id + '/' + role, params).then(function(){ return visRefreshGatePasses(); }); }).catch(function(err){
        var msg = (err && err.message) || '';
        if (role === 'teacher' && /delegate/i.test(msg)) {
          askText({
            title: 'Delegate approval required',
            message: msg,
            fields: [{name: 'delegateReason', label: 'Reason for approving as a manage-level delegate', required: true}],
            okLabel: 'Approve as delegate',
          }).then(function(vals2){
            if (!vals2) return;
            params.delegateReason = vals2.delegateReason;
            withBusy(btn, function(){ return postForm(BASE + 'campus_access/record_approval/' + id + '/' + role, params).then(function(){ return visRefreshGatePasses(); }); }).catch(fail);
          });
          return;
        }
        fail(err);
      });
    });
  };
  window.visGpRelease = function(btn, id) {
    askConfirm({title: 'Confirm release', message: 'Confirm the person is physically leaving now — this cannot be undone.', okLabel: 'Confirm release', danger: true}).then(function(ok){
      if (!ok) return;
      withBusy(btn, function(){ return postForm(BASE + 'campus_access/release_gate_pass/' + id, {verificationMethod: 'manual'}).then(function(){ return visRefreshGatePasses(); }); }).catch(fail);
    });
  };
  var gpFilterStatus = document.getElementById('gpFilterStatus');
  window.visRefreshGatePasses = function(btn) {
    document.getElementById('gpRows').innerHTML = skeletonRows([130, 80, 120, 100, 90, 90]);
    return withBusy(btn, function(){
      return withCover('gpTableWrap', 'Loading gate passes…', function(){
        return get(BASE + 'campus_access/list_gate_passes', {status: gpFilterStatus.value}).then(function(res){
          var items = (res && res.items) || [];
          document.getElementById('gpRows').innerHTML = items.length
            ? items.map(function(r){
                var lbl = GP_STATUS_LABEL[r.status] || [r.status, 'vis-badge-grey'];
                return '<tr><td>' + esc(r.personNameSnapshot) + ' <span class="hint">(' + esc(r.personType) + ')</span></td><td>' + esc(r.type) + '</td><td>' + esc(r.reason) + gpAgeHint(r.updatedAt) +
                  '</td><td>' + gpApprovalSummary(r.approvals) + '</td><td><span class="vis-badge ' + lbl[1] + '">' + esc(lbl[0]) + '</span></td><td>' + gpActionButtons(r) + '</td></tr>';
              }).join('')
            : '<tr><td colspan="6" class="vis-empty">No gate passes match this filter.</td></tr>';
        });
      });
    }).catch(fail);
  };
  gpFilterStatus.addEventListener('change', function(){ visRefreshGatePasses(); });

  // ── Muster / Roll-Call ───────────────────────────────────────────────
  window.visRefreshMuster = function(btn) {
    document.getElementById('musterRows').innerHTML = skeletonRows([70, 110, 90, 90]);
    return withBusy(btn, function(){
      return withCover('musterTableWrap', 'Building the roll-call…', function(){
        return get(BASE + 'campus_access/muster').then(function(res){
          document.getElementById('musterStudent').textContent = (res.counts && res.counts.student) || 0;
          document.getElementById('musterStaff').textContent = (res.counts && res.counts.staff) || 0;
          document.getElementById('musterVisitor').textContent = (res.counts && res.counts.visitor) || 0;
          document.getElementById('musterMeta').textContent = 'Generated ' + fmtTime(res.generatedAt) + ' · scanned ' + (res.scanned || 0) + ' recent ledger events';
          var rows = [];
          ['student', 'staff', 'visitor'].forEach(function(type){
            ((res.onCampus && res.onCampus[type]) || []).forEach(function(p){
              rows.push('<tr><td>' + esc(type) + '</td><td>' + esc(p.name || '—') + '</td><td class="mono">' + esc(p.id) + '</td><td>' + fmtTime(p.since) + '</td></tr>');
            });
          });
          document.getElementById('musterRows').innerHTML = rows.length ? rows.join('') : '<tr><td colspan="4" class="vis-empty">No one on campus right now.</td></tr>';
        });
      });
    }).catch(fail);
  };

  // ── Log ───────────────────────────────────────────────────────────
  var logFilterStatus = document.getElementById('logFilterStatus');
  var logFilterPurpose = document.getElementById('logFilterPurpose');
  var logExportLink = document.getElementById('logExportLink');

  function logFilterParams() {
    return {status: logFilterStatus.value, purpose: logFilterPurpose.value};
  }
  function logUpdateExportLink() {
    var qs = new URLSearchParams(logFilterParams()).toString();
    logExportLink.href = BASE + 'campus_access/export_log_csv' + (qs ? '?' + qs : '');
  }
  /**
   * UI hardening (2026-08-10): this is a plain <a href> triggering a native
   * browser file download (Content-Disposition: attachment), not a fetch()
   * — there is no XHR promise here to hang a real loading state off, and
   * intercepting the click to fake one would mean reimplementing the
   * download via fetch()+blob for no real benefit (the browser's own
   * download indicator already exists). What was missing was ANY immediate
   * acknowledgement that the click registered at all before the browser's
   * own download UI kicks in. A short toast does that without touching the
   * actual navigation — deliberately no preventDefault().
   */
  logExportLink.addEventListener('click', function(){ toast('Preparing export…'); });

  window.visRefreshLog = function() {
    logUpdateExportLink();
    document.getElementById('logRows').innerHTML = skeletonRows([120, 90, 80, 70, 70, 70, 60]);
    return withCover('logTableWrap', 'Loading the log…', function(){
      return get(BASE + 'campus_access/list_log', logFilterParams()).then(function(res){
        var items = (res && res.items) || [];
        document.getElementById('logRows').innerHTML = items.length
          ? items.map(function(r){
              return '<tr><td>' + avatarCell(r.visitorNameSnapshot, r.visitorPhotoUrl) + '</td><td>' + purposeLabel(r) + '</td><td>' + esc(r.hostStaffNameSnapshot || '—') +
                '</td><td>' + statusChip(r.status) + '</td><td>' + fmtTime(r.checkInAt) + '</td><td>' + fmtTime(r.checkOutAt) +
                '</td><td>' + (r.watchlistHit ? '<span class="vis-badge vis-badge-red">Yes</span>' : '—') + '</td></tr>';
            }).join('')
          : '<tr><td colspan="7" class="vis-empty">No visits match these filters.</td></tr>';
      }).catch(fail);
    });
  };
  logFilterStatus.addEventListener('change', visRefreshLog);
  logFilterPurpose.addEventListener('change', visRefreshLog);

  // ── Watchlist ─────────────────────────────────────────────────────
  window.visRefreshWatchlist = function() {
    document.getElementById('wlRows').innerHTML = skeletonRows([100, 90, 60, 120, 70, 70]);
    return withCover('wlTableWrap', 'Loading the watchlist…', function(){
      return get(BASE + 'campus_access/watchlist_list').then(function(res){
        var items = (res && res.items) || [];
        document.getElementById('wlRows').innerHTML = items.length
          ? items.map(function(r){
              return '<tr><td>' + esc(r.name || '—') + '</td><td>' + esc(r.phone || '—') + '</td><td>' +
                (r.severity === 'block' ? '<span class="vis-badge vis-badge-red">Block</span>' : '<span class="vis-badge vis-badge-amber">Flag</span>') +
                '</td><td>' + esc(r.reason) + '</td><td>' + fmtTime(r.addedAt) +
                '</td><td><button class="vis-btn vis-btn-sm" data-busy-label="Removing…" onclick="visDeactivateWl(this,\'' + r.id + '\')">Remove</button></td></tr>';
            }).join('')
          : '<tr><td colspan="6" class="vis-empty">No active watchlist entries.</td></tr>';
      }).catch(fail);
    });
  };
  window.visDeactivateWl = function(btn, id) {
    askText({title: 'Remove watchlist entry', fields: [{name: 'reason', label: 'Reason for removing this entry', required: true}], okLabel: 'Remove', danger: true}).then(function(vals){
      if (!vals) return;
      withBusy(btn, function(){ return postForm(BASE + 'campus_access/watchlist_deactivate/' + id, {reason: vals.reason}).then(visRefreshWatchlist); }).catch(fail);
    });
  };
  var wlForm = document.getElementById('wlForm');
  if (wlForm) {
    wlForm.addEventListener('submit', function(e){
      e.preventDefault();
      var wlBtn = e.target.querySelector('button[type=submit]');
      var params = new URLSearchParams(new FormData(e.target));
      withBusy(wlBtn, function(){ return postForm(BASE + 'campus_access/watchlist_add', params); }).then(function(){
        e.target.reset();
        visRefreshWatchlist();
      }).catch(fail);
    });
  }

  visRefreshDesk();
})();
</script>
