<?php defined('BASEPATH') or exit('No direct script access allowed');
/**
 * Superadmin → Maps Platform.
 *
 * Configures the ZenX platform DEFAULT that every school inherits unless it
 * sets its own override. Nothing here is tenant data.
 *
 * SECRETS ARE NEVER RENDERED. The controller passes credential KEY NAMES only
 * (`public_keys`), so this template cannot leak a value even by accident —
 * there is no value in scope to print. A saved credential shows as "stored".
 */
$STATE_LABEL = [
    'untested' => ['Not tested', '#8a6d00', '#fff8e1'],
    'valid'    => ['Valid',      '#1b5e20', '#e8f5e9'],
    'invalid'  => ['Invalid',    '#b71c1c', '#ffebee'],
];
?>
<style>
.mp-wrap{padding:20px 22px 40px;max-width:1100px}
.mp-card{background:#fff;border:1px solid #e3e6ea;border-radius:8px;padding:18px 20px;margin-bottom:18px}
.mp-card h3{margin:0 0 4px;font-size:16px}
.mp-sub{font-size:12px;color:#6b7280;margin-bottom:14px}
.mp-row{display:flex;gap:16px;flex-wrap:wrap}
.mp-f{flex:1;min-width:220px;margin-bottom:12px}
.mp-f label{display:block;font-size:11px;letter-spacing:.04em;text-transform:uppercase;color:#6b7280;margin-bottom:4px}
.mp-f input,.mp-f select,.mp-f textarea{width:100%;padding:8px 10px;border:1px solid #d7dbe0;border-radius:6px;font-size:13px}
.mp-pill{display:inline-block;padding:2px 9px;border-radius:11px;font-size:11px;font-weight:600}
.mp-btn{padding:7px 14px;border:1px solid #d7dbe0;background:#fff;border-radius:6px;font-size:13px;cursor:pointer}
.mp-btn-primary{background:#2c5282;color:#fff;border-color:#2c5282}
.mp-btn:disabled{opacity:.45;cursor:not-allowed}
.mp-na{background:#f7f8fa;border:1px dashed #d7dbe0;border-radius:8px;padding:16px 18px;margin-bottom:18px;color:#6b7280;font-size:13px}
.mp-keys{font-size:12px;color:#374151}
.mp-warn{background:#fff8e1;border:1px solid #ffe082;color:#8a6d00;border-radius:6px;padding:9px 11px;font-size:12px;margin-bottom:12px}
</style>

<div class="content-wrapper">
<div class="mp-wrap">

  <h2 style="margin:0 0 4px">Maps Platform</h2>
  <div class="mp-sub">
    The default map provider every school inherits. A school may override it in
    its own Maps &amp; Location settings; changing the default here moves only
    the schools that are inheriting.
  </div>

  <!-- ── Environment + commercial guardrails ─────────────────────────── -->
  <div class="mp-card">
    <h3>Environment</h3>
    <div class="mp-sub">
      <b>Commercial status is metadata, not proof of a licence.</b> It records what
      was arranged so an obviously wrong combination can be blocked — a credential
      marked free / non-commercial cannot be activated in a production environment.
    </div>
    <div class="mp-row">
      <div class="mp-f"><label>Environment</label>
        <select id="mpEnv">
          <?php foreach (['dev','uat','production'] as $e): ?>
            <option value="<?= $e ?>" <?= $environment === $e ? 'selected' : '' ?>><?= strtoupper($e) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mp-f"><label>Commercial status</label>
        <select id="mpCom">
          <?php foreach (['unknown'=>'Unknown','free_noncommercial'=>'Free / non-commercial','paid'=>'Paid plan','custom'=>'Custom / negotiated'] as $k=>$v): ?>
            <option value="<?= $k ?>" <?= $commercial === $k ? 'selected' : '' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="mp-f"><label>Note (plan, contract reference)</label>
        <input type="text" id="mpNote" value="<?= html_escape($note) ?>" placeholder="e.g. Flex plan, renewed 2026-08">
      </div>
    </div>
    <button class="mp-btn mp-btn-primary" onclick="mpSaveMeta()">Save environment</button>
  </div>

  <!-- ── Capabilities ─────────────────────────────────────────────────── -->
  <?php foreach ($caps as $cap): $k = $cap['key']; ?>
    <?php if (!$cap['implemented']): ?>
      <div class="mp-na">
        <b><?= ucfirst($k) ?></b> — <b>not implemented</b>.
        Providers may advertise this capability, but ZenX does not consume it yet, so it
        cannot be configured or activated. Design only.
      </div>
      <?php continue; ?>
    <?php endif; ?>

    <?php
      $st  = $STATE_LABEL[$cap['validation']] ?? $STATE_LABEL['untested'];
      $act = $cap['enabled'];
    ?>
    <div class="mp-card" data-cap="<?= $k ?>">
      <div style="display:flex;align-items:center;gap:10px">
        <h3 style="flex:1"><?= ucfirst($k) ?></h3>
        <span class="mp-pill" style="color:<?= $st[1] ?>;background:<?= $st[2] ?>"><?= $st[0] ?></span>
        <span class="mp-pill" style="color:<?= $act ? '#1b5e20' : '#6b7280' ?>;background:<?= $act ? '#e8f5e9' : '#f1f3f5' ?>">
          <?= $act ? 'Active' : 'Inactive' ?>
        </span>
      </div>
      <div class="mp-sub">
        <?= $cap['validated_at'] ? 'Last tested ' . html_escape((string) $cap['validated_at']) : 'Never tested' ?>
      </div>
      <?php if ($cap['error'] !== ''): ?>
        <div class="mp-warn"><?= html_escape($cap['error']) ?></div>
      <?php endif; ?>

      <div class="mp-row">
        <div class="mp-f"><label>Provider</label>
          <select class="mpProv">
            <option value="">— none —</option>
            <?php foreach ($cap['providers'] as $p): ?>
              <option value="<?= $p ?>" <?= $cap['provider'] === $p ? 'selected' : '' ?>>
                <?= html_escape($registry[$p]['label'] ?? $p) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <?php foreach ($registry as $pid => $pdef): ?>
        <div class="mpCreds" data-p="<?= $pid ?>" style="display:<?= $cap['provider'] === $pid ? 'block' : 'none' ?>">
          <div class="mp-row">
          <?php foreach ((array) ($pdef['credentials'] ?? []) as $ckey => $cmeta):
                  if (($cmeta['sensitivity'] ?? 'public') !== 'public') { continue; }
                  $stored = in_array($ckey, $cap['public_keys'], true); ?>
            <div class="mp-f">
              <label><?= html_escape($cmeta['label'] ?? $ckey) ?><?= !empty($cmeta['required']) ? ' *' : '' ?></label>
              <input type="text" class="mpCred" data-k="<?= $ckey ?>"
                     placeholder="<?= $stored ? 'stored — leave blank to keep' : html_escape($cmeta['hint'] ?? '') ?>">
              <?php if ($stored): ?><div class="mp-keys">✓ stored</div><?php endif; ?>
            </div>
          <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>

      <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:6px">
        <button class="mp-btn" onclick="mpSave('<?= $k ?>')">Save configuration</button>
        <button class="mp-btn" onclick="mpTest('<?= $k ?>')">Test connection</button>
        <button class="mp-btn mp-btn-primary" onclick="mpActivate('<?= $k ?>')"
                <?= $cap['validation'] === 'valid' ? '' : 'disabled title="Test successfully before activating"' ?>>Activate</button>
        <button class="mp-btn" onclick="mpDeactivate('<?= $k ?>')" <?= $act ? '' : 'disabled' ?>>Deactivate</button>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="mp-sub">
    Last updated <?= html_escape((string) ($updated_at ?: '—')) ?>
    by <?= html_escape((string) ($updated_by ?: '—')) ?>
  </div>
</div>
</div>

<script>
(function () {
  var BASE = '<?= rtrim(base_url(), "/") ?>/';

  // CSRF: the SA layout publishes the token and its field name as meta tags
  // (sa_header.php:11-12) precisely so page JS can attach them. Maps is NOT
  // added to csrf_exclude_uris — an exclusion would be the easy fix and the
  // weaker one; these endpoints change the map provider for EVERY inheriting
  // school, so they keep full CSRF protection. Read per request because the
  // token can rotate.
  function csrfPair() {
    var n = document.querySelector('meta[name="csrf-name"]');
    var t = document.querySelector('meta[name="csrf-token"]');
    var o = {};
    if (n && t && n.content && t.content) { o[n.content] = t.content; }
    return o;
  }

  function post(url, data) {
    return fetch(BASE + url, {
      method: 'POST', credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: new URLSearchParams(Object.assign({}, data, csrfPair())).toString()
    // Fail closed on the transport itself: fetch() does not reject on 4xx/5xx,
    // so an unchecked r.ok would report a denied save as success.
    }).then(function (r) { return r.ok ? r.json() : { status: 'error', message: 'HTTP ' + r.status }; })
      .catch(function () { return { status: 'error', message: 'Network error' }; });
  }
  function done(j) { alert(j.message || (j.status === 'success' ? 'Done' : 'Failed')); if (j.status === 'success') location.reload(); }
  function card(cap) { return document.querySelector('.mp-card[data-cap="' + cap + '"]'); }

  // Show only the selected provider's credential fields.
  document.querySelectorAll('.mpProv').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var c = sel.closest('.mp-card');
      c.querySelectorAll('.mpCreds').forEach(function (b) {
        b.style.display = (b.getAttribute('data-p') === sel.value) ? 'block' : 'none';
      });
    });
  });

  window.mpSave = function (cap) {
    var c = card(cap), prov = c.querySelector('.mpProv').value;
    var d = { capability: cap, provider: prov };
    var box = c.querySelector('.mpCreds[data-p="' + prov + '"]');
    if (box) {
      box.querySelectorAll('.mpCred').forEach(function (i) {
        if (i.value.trim() !== '') { d['public[' + i.getAttribute('data-k') + ']'] = i.value.trim(); }
      });
    }
    post('superadmin/maps/save_capability', d).then(done);
  };
  window.mpTest       = function (c) { post('superadmin/maps/test_capability',       { capability: c }).then(done); };
  window.mpActivate   = function (c) { post('superadmin/maps/activate_capability',   { capability: c }).then(done); };
  window.mpDeactivate = function (c) { post('superadmin/maps/deactivate_capability', { capability: c }).then(done); };
  window.mpSaveMeta   = function () {
    post('superadmin/maps/save_meta', {
      environment: document.getElementById('mpEnv').value,
      commercial_status: document.getElementById('mpCom').value,
      commercial_note: document.getElementById('mpNote').value
    }).then(done);
  };
}());
</script>
