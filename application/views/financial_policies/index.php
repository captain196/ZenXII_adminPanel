<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
/**
 * School Financial Policies & Benefits.
 *
 * The screen exists to answer one question per benefit: does THIS school give
 * it, and on what terms? The thing it must never imply is that verifying a
 * relationship was itself the decision — so the distinction is stated at the
 * top, not buried in help text.
 *
 * Everything financially unsafe is absent by construction. There is no control
 * here for tenant isolation, negative nets, audit logging, idempotency or the
 * protection of service fees from blanket concessions, because those are not
 * things a school gets to choose.
 */
?>
<style>
.fp-wrap{max-width:1080px;margin:0 auto;padding:22px 18px 60px}
.fp-head h2{margin:0 0 4px;font-size:21px;font-weight:700}
.fp-head p{margin:0;font-size:13.5px;opacity:.75;line-height:1.55}
.fp-principle{margin:18px 0 26px;padding:14px 16px;border:1px solid rgba(188,90,60,.3);
  border-radius:10px;background:rgba(188,90,60,.06);font-size:13.5px;line-height:1.6}
.fp-principle b{display:block;margin-bottom:4px;font-size:14px}
.fp-card{border:1px solid rgba(0,0,0,.12);border-radius:12px;margin-bottom:22px;overflow:hidden}
.fp-card-h{display:flex;align-items:center;justify-content:space-between;gap:12px;
  padding:14px 18px;background:rgba(0,0,0,.03);border-bottom:1px solid rgba(0,0,0,.08)}
.fp-card-h h3{margin:0;font-size:15.5px;font-weight:700}
.fp-card-h .fp-sub{font-size:12.5px;opacity:.7;margin-top:2px}
.fp-state{font-size:12px;font-weight:700;padding:4px 11px;border-radius:999px;white-space:nowrap}
.fp-off{background:rgba(120,120,120,.15);color:#555}
.fp-appr{background:rgba(214,158,46,.18);color:#8a6116}
.fp-auto{background:rgba(29,122,68,.15);color:#1d7a44}
.fp-body{padding:18px}
.fp-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:14px}
.fp-f label{display:block;font-size:12px;font-weight:600;opacity:.8;margin-bottom:5px}
.fp-f input,.fp-f select{width:100%;padding:8px 10px;border:1px solid rgba(0,0,0,.2);
  border-radius:7px;font-size:13.5px;background:#fff}
.fp-f input:disabled,.fp-f select:disabled{background:rgba(0,0,0,.04);opacity:.6}
.fp-terms{margin-top:16px}
.fp-terms.is-off{display:none}
.fp-actions{display:flex;align-items:center;gap:12px;margin-top:18px;flex-wrap:wrap}
.fp-btn{padding:9px 18px;border:1px solid rgba(188,90,60,.55);background:#BC5A3C;color:#fff;
  border-radius:8px;font-size:13.5px;font-weight:600;cursor:pointer}
.fp-btn:disabled{opacity:.55;cursor:default}
.fp-msg{font-size:13px}
.fp-msg.ok{color:#1d7a44}
.fp-msg.bad{color:#b23a2f}
.fp-note{margin-top:14px;font-size:12.5px;opacity:.72;line-height:1.55;
  border-top:1px dashed rgba(0,0,0,.15);padding-top:12px}
.fp-guard{margin-top:26px;padding:14px 16px;border:1px solid rgba(0,0,0,.12);
  border-radius:10px;font-size:12.5px;line-height:1.65;opacity:.85}
.fp-guard b{font-size:13px}
.fp-guard ul{margin:8px 0 0 18px;padding:0}
</style>

<div class="fp-wrap">
  <div class="fp-head">
    <h2>Financial Policies &amp; Benefits</h2>
    <p>Decide whether this school offers sibling and staff-ward benefits, and on what terms.
       Settings apply to the <b><?= htmlspecialchars($this->session->userdata('session') ?? '', ENT_QUOTES, 'UTF-8') ?></b> session.</p>
  </div>

  <div class="fp-principle">
    <b>A verified relationship is not a discount.</b>
    Recording that two students are siblings, or that a parent works here, proves who is
    related to whom — nothing more. No fee changes until you enable a benefit below and, where
    you require it, somebody approves each one. Both benefits are <b>off</b> until you turn
    them on.
  </div>

  <div id="fpCards"><p class="fp-msg" id="fpLoading">Loading policies…</p></div>

  <div class="fp-guard">
    <b>Set by the platform — not configurable</b>
    <ul>
      <li>A benefit is only ever granted on a <b>verified</b> relationship. Names, phone numbers
          and guardian text never qualify anyone.</li>
      <li>A discount can never exceed the charge it applies to, and a payable can never go below zero.</li>
      <li>A blanket "all fees" concession never reaches transport, hostel or meals — those need a
          concession scoped to them deliberately.</li>
      <li>Every benefit carries its reason, its policy version, who authorised it and when.</li>
      <li>Repeating an operation never grants the benefit twice.</li>
      <li>Changing a policy never reprices a period that has already been billed or paid.</li>
    </ul>
  </div>
</div>

<script>
(function () {
  var BASE = '<?= base_url() ?>';
  var TOKEN_NAME  = '<?= $this->security->get_csrf_token_name() ?>';
  var TOKEN_VALUE = '<?= $this->security->get_csrf_hash() ?>';

  var BENEFITS = [
    { key: 'sibling', title: 'Sibling benefit',
      sub: 'For a student whose brother or sister is verified on the roll of this school.',
      activeLabel: 'Only while the sibling is still on the roll' },
    { key: 'staff_ward', title: 'Staff / employee ward benefit',
      sub: 'For a student whose parent or guardian is a verified employee of this school.',
      activeLabel: 'Only while the employee is currently employed', children: true }
  ];

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return ({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' })[c];
    });
  }

  function post(path, data) {
    var fd = new FormData();
    Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
    fd.append(TOKEN_NAME, TOKEN_VALUE);
    return fetch(BASE + path, { method: 'POST', body: fd, credentials: 'same-origin' })
      .then(function (r) {
        // Phantom-success guard: fetch() does not reject on 403 or 500, so a
        // denied save would otherwise render as "Saved". Check the HTTP status
        // AND the envelope before believing anything.
        return r.json()
          .then(function (b) { return { httpOk: r.ok, body: b }; })
          .catch(function () { return { httpOk: false, body: null }; });
      });
  }

  function stateChip(mode) {
    if (mode === 'automatic') return '<span class="fp-state fp-auto">Automatic</span>';
    if (mode === 'approval')  return '<span class="fp-state fp-appr">Needs approval</span>';
    return '<span class="fp-state fp-off">Off</span>';
  }

  function card(def, pol) {
    var m = (pol && pol.mode) || 'disabled';
    var v = pol || {};
    return ''
    + '<div class="fp-card" data-benefit="' + def.key + '">'
    +   '<div class="fp-card-h">'
    +     '<div><h3>' + esc(def.title) + '</h3><div class="fp-sub">' + esc(def.sub) + '</div></div>'
    +     '<div data-role="chip">' + stateChip(m) + '</div>'
    +   '</div>'
    +   '<div class="fp-body">'
    +     '<div class="fp-grid">'
    +       '<div class="fp-f"><label>Is this benefit offered?</label>'
    +         '<select data-f="mode">'
    +           '<option value="disabled"' + (m==='disabled'?' selected':'') + '>No — not offered</option>'
    +           '<option value="approval"' + (m==='approval'?' selected':'') + '>Yes — each one needs approval</option>'
    +           '<option value="automatic"' + (m==='automatic'?' selected':'') + '>Yes — granted automatically</option>'
    +         '</select></div>'
    +     '</div>'
    +     '<div class="fp-terms' + (m==='disabled'?' is-off':'') + '" data-role="terms">'
    +       '<div class="fp-grid">'
    +         '<div class="fp-f"><label>Benefit type</label><select data-f="value_type">'
    +           '<option value="percent"' + (v.valueType==='fixed'?'':' selected') + '>Percentage</option>'
    +           '<option value="fixed"' + (v.valueType==='fixed'?' selected':'') + '>Fixed amount</option>'
    +         '</select></div>'
    +         '<div class="fp-f"><label>Value</label>'
    +           '<input type="number" step="0.01" min="0" data-f="value" value="' + esc(v.value || '') + '"></div>'
    +         '<div class="fp-f"><label>Applies to</label>'
    +           '<input type="text" data-f="scope" value="' + esc(v.scope || 'all') + '" '
    +           'placeholder="all, category:tuition, head:FH_..."></div>'
    +         '<div class="fp-f"><label>Effective from</label>'
    +           '<input type="date" data-f="effective_from" value="' + esc(v.effectiveFrom || '') + '"></div>'
    +         '<div class="fp-f"><label>Effective to (optional)</label>'
    +           '<input type="date" data-f="effective_to" value="' + esc(v.effectiveTo || '') + '"></div>'
    +         '<div class="fp-f"><label>Maximum benefit (0 = no cap)</label>'
    +           '<input type="number" step="0.01" min="0" data-f="max_benefit" value="' + esc(v.maxBenefit || 0) + '"></div>'
    +         '<div class="fp-f"><label>' + esc(def.activeLabel) + '</label><select data-f="require_active">'
    +           '<option value="1"' + (v.requireSiblingActive === false || v.requireStaffActive === false ? '' : ' selected') + '>Yes</option>'
    +           '<option value="0"' + (v.requireSiblingActive === false || v.requireStaffActive === false ? ' selected' : '') + '>No</option>'
    +         '</select></div>'
    +         (def.children
    +           ? '<div class="fp-f"><label>Children covered per employee (0 = no limit)</label>'
    +             + '<input type="number" min="0" step="1" data-f="max_children" value="' + esc(v.maxChildren || 0) + '"></div>'
    +           : '')
    +       '</div>'
    +     '</div>'
    +     '<div class="fp-actions">'
    +       '<button class="fp-btn" data-role="save">Save policy</button>'
    +       '<span class="fp-msg" data-role="msg"></span>'
    +     '</div>'
    +     '<div class="fp-note">Saving records a new policy version with your name and the time. '
    +       'It governs benefits evaluated from that point on — periods already billed or paid are not repriced.</div>'
    +   '</div>'
    + '</div>';
  }

  function wire(el) {
    var benefit = el.getAttribute('data-benefit');
    var modeEl  = el.querySelector('[data-f="mode"]');
    var terms   = el.querySelector('[data-role="terms"]');
    var chip    = el.querySelector('[data-role="chip"]');
    var msg     = el.querySelector('[data-role="msg"]');
    var btn     = el.querySelector('[data-role="save"]');

    function sync() {
      var off = modeEl.value === 'disabled';
      terms.classList.toggle('is-off', off);
      chip.innerHTML = stateChip(modeEl.value);
    }
    modeEl.addEventListener('change', sync);

    btn.addEventListener('click', function () {
      var data = { benefit: benefit };
      el.querySelectorAll('[data-f]').forEach(function (f) { data[f.getAttribute('data-f')] = f.value; });

      msg.className = 'fp-msg'; msg.textContent = 'Saving…';
      btn.disabled = true;

      post('financial_policies/save_policy', data).then(function (res) {
        if (!res.httpOk || !res.body || res.body.status !== 'success') {
          msg.className = 'fp-msg bad';
          msg.textContent = (res.body && res.body.message) ? res.body.message : 'Could not save.';
          return;
        }
        msg.className = 'fp-msg ok';
        msg.textContent = res.body.message || 'Saved.';
      }).catch(function () {
        msg.className = 'fp-msg bad';
        msg.textContent = 'Could not save. Nothing was changed.';
      }).then(function () { btn.disabled = false; });
    });
  }

  post('financial_policies/get_policies', {}).then(function (res) {
    var host = document.getElementById('fpCards');
    if (!res.httpOk || !res.body || res.body.status !== 'success') {
      host.innerHTML = '<p class="fp-msg bad">Could not load policies. '
                     + 'No benefit is granted while this is unreadable.</p>';
      return;
    }
    var pols = res.body.policies || {};
    host.innerHTML = BENEFITS.map(function (d) { return card(d, pols[d.key]); }).join('');
    host.querySelectorAll('.fp-card').forEach(wire);
  });
})();
</script>
