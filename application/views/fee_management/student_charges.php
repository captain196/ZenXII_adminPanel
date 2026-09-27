<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<div class="content-wrapper">
  <section class="content-header fm-topbar">
    <div class="fm-topbar-inner">
      <h1 class="fm-page-title"><i class="fa fa-file-text-o"></i> Student Charges</h1>
      <ol class="breadcrumb fm-breadcrumb">
        <li><a href="<?= base_url('dashboard') ?>"><i class="fa fa-dashboard"></i> Dashboard</a></li>
        <li><a href="<?= base_url('fees') ?>">Fees</a></li>
        <li class="active">Student Charges</li>
      </ol>
    </div>
  </section>

  <section class="content fm-content">

    <div class="sc-card">
      <h3>Raise a one-off charge</h3>
      <p class="sc-sub">
        A charge against ONE student — a lost book, a breakage, a trip contribution.
        It becomes a normal fee demand the family can see and pay in the Parent app,
        due 30 days from today.
      </p>

      <div class="sc-form">
        <div class="sc-field">
          <label for="scStudent">Student</label>
          <select id="scStudent">
            <option value="">— select a student —</option>
            <?php foreach (($students ?? []) as $sid => $stu): ?>
              <option value="<?= html_escape($sid) ?>">
                <?php $sr = trim((string) ($stu['sr_no'] ?? '')); ?>
                <?= $sr !== '' ? 'SR ' . html_escape($sr) . ' · ' : '' ?><?= html_escape($sid . ' · ' . ($stu['name'] ?? '')) ?><?php
                  $cs = trim(($stu['class'] ?? '') . ' ' . ($stu['section'] ?? ''));
                  echo $cs !== '' ? ' (' . html_escape($cs) . ')' : ''; ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="sc-field">
          <label for="scHead">Fee head</label>
          <?php if (empty($feeHeads)): ?>
            <select id="scHead" disabled><option value="">— none registered —</option></select>
          <?php else:
            // A one-off charge is a COST RECOVERY, not part of the fee
            // structure. Booking one against Tuition or Transport credits that
            // revenue account and overstates fee income — the SC-ACCT-1 shape.
            // 'administrative' is the category the working example uses
            // (ID Card Replacement -> 4140 Miscellaneous Income), so those
            // heads are offered first and the structural ones are separated
            // rather than hidden: a school may have a legitimate reason to use
            // one, but it should be a deliberate choice, not the default.
            $recovery = $structural = [];
            foreach ($feeHeads as $h) {
                $cat = strtolower((string) ($h['category'] ?? ''));
                if ($cat === 'administrative' || $cat === '') $recovery[] = $h;
                else $structural[] = $h;
            }
          ?>
            <select id="scHead">
              <option value="">— select a fee head —</option>
              <?php if ($recovery): ?>
                <optgroup label="One-off / cost recovery">
                  <?php foreach ($recovery as $h): ?>
                    <option value="<?= html_escape($h['name'] ?? '') ?>" data-structural="0"><?= html_escape($h['name'] ?? '') ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
              <?php if ($structural): ?>
                <optgroup label="Fee-structure heads — not intended for one-off charges">
                  <?php foreach ($structural as $h): ?>
                    <option value="<?= html_escape($h['name'] ?? '') ?>" data-structural="1"><?= html_escape($h['name'] ?? '') ?> · <?= html_escape($h['category'] ?? '') ?></option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endif; ?>
            </select>
          <?php endif; ?>
          <p id="scHeadWarn" style="display:none;margin:6px 0 0;font-size:12px;color:var(--gold);line-height:1.45;"></p>
        </div>

        <div class="sc-field">
          <label for="scReason">Reason <span class="sc-req">*</span></label>
          <input type="text" id="scReason" placeholder="What is this charge for? The family will see this.">
        </div>

        <div class="sc-field">
          <label for="scAmount">Amount (Rs.)</label>
          <input type="number" id="scAmount" min="0.01" step="0.01" placeholder="350">
        </div>

        <div class="sc-field">
          <button id="scRaise" class="sc-btn">Raise charge</button>
        </div>
      </div>

      <?php if (empty($feeHeads)): ?>
        <p class="sc-sub" style="margin:14px 0 0;color:var(--gold);">
          This school has no registered fee heads, so no charge can be raised.
          A charge may only use a registered head — register one first.
        </p>
      <?php endif; ?>
      <p id="scMsg" class="sc-sub" style="margin:14px 0 0;font-weight:600;"></p>
    </div>

    <div class="sc-card">
      <h3>Charges for the selected student</h3>
      <p class="sc-sub" id="scCount">Select a student to see their charges.</p>

      <div style="overflow-x:auto;">
        <table class="sc-table">
          <thead>
            <tr>
              <th>Charge</th>
              <th style="width:15%">Head</th>
              <th class="sc-num" style="width:11%">Amount</th>
              <th class="sc-num" style="width:10%">Paid</th>
              <th style="width:12%">Due</th>
              <th style="width:11%">Status</th>
              <th style="width:9%"></th>
            </tr>
          </thead>
          <tbody id="scRows">
            <tr><td colspan="8" class="sc-empty">No student selected.</td></tr>
          </tbody>
        </table>
      </div>
    </div>

  </section>
</div>

<!-- Self-contained: showToast() is defined per-page across this panel, not globally. -->
<div id="scToastWrap" style="position:fixed;top:78px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;"></div>
<style>
  /* Uses the panel's own tokens (--gold, --card, --t1/2, --border, --font-*),
     so this page follows the day/night theme instead of hard-coding colours. */
  .sc-card{background:var(--card);border:1px solid var(--border);border-radius:14px;
    padding:22px 24px;margin-bottom:20px;}
  .sc-card h3{font-family:var(--font-d);font-size:18px;margin:0 0 4px;color:var(--t1);}
  .sc-card .sc-sub{margin:0 0 18px;color:var(--t2);font-size:13px;line-height:1.5;}

  .sc-form{display:grid;grid-template-columns:1.4fr 1.4fr 2fr .8fr auto;gap:16px;align-items:end;}
  @media (max-width:1100px){ .sc-form{grid-template-columns:1fr 1fr;} }
  .sc-field label{display:block;font-size:11px;font-weight:700;letter-spacing:.06em;
    text-transform:uppercase;color:var(--t2);margin-bottom:6px;}
  .sc-field select,.sc-field input{width:100%;height:42px;padding:0 12px;border-radius:9px;
    border:1px solid var(--brd2);background:var(--bg2);color:var(--t1);
    font-family:var(--font-b);font-size:14px;}
  .sc-field select:focus,.sc-field input:focus{outline:none;border-color:var(--gold);
    box-shadow:0 0 0 3px var(--gold-ring);}
  .sc-field input[type=number]{font-variant-numeric:tabular-nums;}
  .sc-req{color:var(--gold);}

  .sc-btn{height:42px;padding:0 20px;border:0;border-radius:9px;background:var(--gold);
    color:#fff;font-family:var(--font-b);font-weight:700;font-size:14px;cursor:pointer;
    white-space:nowrap;transition:background var(--ease);}
  .sc-btn:hover:not(:disabled){background:var(--gold2);}
  .sc-btn:disabled{opacity:.55;cursor:not-allowed;}

  .sc-table{width:100%;border-collapse:collapse;}
  .sc-table th{font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;
    color:var(--t2);text-align:left;padding:0 14px 10px;border-bottom:1px solid var(--border);}
  .sc-table td{padding:14px;border-bottom:1px solid var(--border);color:var(--t1);font-size:14px;
    vertical-align:middle;}
  .sc-table tr:last-child td{border-bottom:0;}
  /* Money and dates line up in columns — tabular figures, right-aligned. */
  .sc-num{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap;}
  .sc-id{font-family:var(--font-m);font-size:11px;color:var(--t2);opacity:.75;margin-top:3px;}
  .sc-reason{font-weight:600;line-height:1.35;}
  .sc-due{white-space:nowrap;font-variant-numeric:tabular-nums;}
  .sc-empty{padding:34px 14px;text-align:center;color:var(--t2);}

  /* Status carries meaning, so it gets a shape as well as a word. */
  .sc-pill{display:inline-block;padding:4px 11px;border-radius:999px;font-size:11px;
    font-weight:700;letter-spacing:.04em;text-transform:uppercase;white-space:nowrap;}
  .sc-pill-raised{background:rgba(217,119,6,.13);color:#b45309;}
  .sc-pill-paid{background:rgba(21,128,61,.13);color:var(--green);}
  .sc-pill-partial{background:rgba(74,181,227,.16);color:#1d6fa5;}
  .sc-pill-waived{background:var(--gold-dim);color:var(--t2);}

  .sc-waive{height:32px;padding:0 14px;border-radius:8px;border:1px solid var(--brd2);
    background:transparent;color:var(--t1);font-size:12px;font-weight:600;cursor:pointer;
    transition:all var(--ease);}
  .sc-waive:hover{border-color:var(--gold);color:var(--gold);}
  .sc-locked{font-size:12px;color:var(--t2);}
</style>
<style>
  .sc-toast{min-width:280px;max-width:460px;padding:12px 16px;border-radius:6px;color:#fff;
    font-weight:600;box-shadow:0 4px 14px rgba(0,0,0,.22);opacity:1;transition:opacity .35s ease;}
  .sc-toast-success{background:#27ae60;}
  .sc-toast-error{background:#c0392b;}
  .sc-toast-info{background:#2c7fb8;}
  .sc-toast-hide{opacity:0;}
</style>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var BASE     = '<?= base_url() ?>';
  var csrfName = '<?= $this->security->get_csrf_token_name() ?>';
  var csrfHash = '<?= $this->security->get_csrf_hash() ?>';

  function csrf() { var o = {}; o[csrfName] = csrfHash; return o; }
  function updateCsrf(d) {
    if (d && d.csrf_hash) csrfHash = d.csrf_hash;
    if (d && d[csrfName]) csrfHash = d[csrfName];
  }

  function form(obj) {
    var p = new URLSearchParams();
    var c = csrf();
    for (var k in c)   p.append(k, c[k]);
    for (var k in obj) p.append(k, obj[k]);
    return p;
  }

  // An error must not depend on the operator happening to look at one span.
  // A refusal here means a charge was NOT raised against a family, so it is
  // held for 8s rather than the usual 3.5s and is not auto-dismissed silently.
  function showToast(text, type) {
    var wrap = document.getElementById('scToastWrap');
    if (!wrap) return;
    var el = document.createElement('div');
    el.className = 'sc-toast sc-toast-' + (type || 'info');
    var icons = { success: 'check-circle', error: 'times-circle', info: 'info-circle' };
    var i = document.createElement('i');
    i.className = 'fa fa-' + (icons[type] || 'info-circle');
    el.appendChild(i);
    // textContent, never innerHTML — a server message can carry a fee head
    // name an operator typed, and that must never be parsed as markup.
    el.appendChild(document.createTextNode(' ' + text));
    wrap.appendChild(el);
    setTimeout(function () {
      el.classList.add('sc-toast-hide');
      setTimeout(function () { el.remove(); }, 350);
    }, type === 'error' ? 8000 : 3500);
  }

  // The inline line stays as the persistent record; the toast is what an
  // operator actually notices.
  function msg(text, ok) {
    var el = document.getElementById('scMsg');
    el.textContent = text;
    el.style.color = ok ? '#27ae60' : '#c0392b';
    if (text) showToast(text, ok ? 'success' : 'error');
  }

  // Fail closed on BOTH counts: fetch() does not reject on 4xx/5xx, and the
  // API reports business failures as 200 + {status:'error'}. Checking only one
  // of those reports a refused charge as raised.
  function post(url, body) {
    return fetch(BASE + url, {
      method: 'POST',
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      body: form(body),
      credentials: 'same-origin'
    }).then(function (r) {
      // Parse the body FIRST, even on a non-2xx. This endpoint answers a
      // refusal with a 400 AND a useful message; throwing on `r.ok` before
      // reading the body replaced "Lost Book is not a registered fee head"
      // with "Server returned 400" and left the operator with nothing to act
      // on. The status still matters — it is the fallback when there is no
      // parseable body at all.
      return r.text().then(function (t) {
        var d = null;
        try { d = JSON.parse(t); } catch (e) { /* not JSON — fall through */ }
        if (d) updateCsrf(d);

        // DECIDE FAILURE FIRST, then choose the wording. An earlier version
        // threw on any `d.message`, and a SUCCESS response carries one too
        // ('Charge of 350.00 raised for this student.') — so a charge that
        // was actually raised surfaced as an error and the list never
        // refreshed. Phantom failure, the mirror of the phantom-success trap:
        // both come from reading one signal instead of the pair.
        var failed = !r.ok || !d || d.status === 'error';
        if (failed) {
          // The server's own message is the useful one — it names the
          // unregistered fee head, the missing reason, the refused waiver.
          throw new Error((d && d.message) || ('Server returned ' + r.status));
        }
        return d;
      });
    });
  }

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;' }[c];
    });
  }

  function money(v) {
    return Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }

  function pill(status, paid, amount) {
    // The stored status says 'raised' until something happens to it, so the
    // money decides what an operator actually needs to see.
    var s = String(status || '').toLowerCase();
    if (s === 'waived') return ['waived', 'Waived'];
    if (paid > 0.005 && paid + 0.005 >= amount) return ['paid', 'Paid'];
    if (paid > 0.005) return ['partial', 'Part paid'];
    return ['raised', 'Raised'];
  }

  function render(rows) {
    var tb = document.getElementById('scRows');
    var cn = document.getElementById('scCount');

    if (!rows || !rows.length) {
      tb.innerHTML = '<tr><td colspan="8" class="sc-empty">No charges for this student.</td></tr>';
      cn.textContent = 'No charges raised against this student.';
      return;
    }

    var outstanding = rows.reduce(function (t, c) {
      if (String(c.status).toLowerCase() === 'waived') return t;
      return t + Math.max(0, Number(c.amount || 0) - Number(c.paidAmount || 0));
    }, 0);
    cn.textContent = rows.length + (rows.length === 1 ? ' charge' : ' charges')
      + ' · Rs. ' + money(outstanding) + ' outstanding';

    tb.innerHTML = rows.map(function (c) {
      var paid   = Number(c.paidAmount || 0);
      var amount = Number(c.amount || 0);
      var p      = pill(c.status, paid, amount);
      // A charge carrying money can only be refunded, never waived — waiving
      // would strand the receipt and its journal entry.
      var canWaive = p[0] !== 'waived' && paid <= 0.005;

      // The REASON is what this charge is; the chargeId is provenance an
      // operator never types or quotes in conversation. Leading with the id
      // made the widest, boldest column the least meaningful one — it is now
      // secondary text under the reason, still selectable for support.
      return '<tr>' +
        '<td>' +
          '<div class="sc-reason">' + esc(c.reason || '(no reason recorded)') + '</div>' +
          '<div class="sc-id" title="Charge reference">' + esc(c.chargeId || '') + '</div>' +
        '</td>' +
        '<td>' + esc(c.feeHead || '') + '</td>' +
        '<td class="sc-num">' + money(amount) + '</td>' +
        '<td class="sc-num">' + money(paid) + '</td>' +
        '<td class="sc-due">' + esc((c.dueDate || '—').substring(0, 10)) + '</td>' +
        '<td><span class="sc-pill sc-pill-' + p[0] + '">' + p[1] + '</span></td>' +
        '<td class="sc-num">' +
          (canWaive
            ? '<button class="sc-waive" data-id="' + esc(c.chargeId) + '">Waive</button>'
            : (paid > 0.005 ? '<span class="sc-locked">refund only</span>' : '')) +
        '</td></tr>';
    }).join('');
  }
  function load() {
    var sid = document.getElementById('scStudent').value;
    if (!sid) { render([]); return; }
    post('fee_management/list_student_charges', { student_id: sid })
      .then(function (d) { render(d.charges || d.data || []); })
      .catch(function (e) {
        document.getElementById('scRows').innerHTML =
          '<tr><td colspan="8" style="color:#c0392b">Could not load charges: ' + esc(e.message) + '</td></tr>';
      });
  }

  // Remember the student across reloads. Raising a charge and then checking
  // it is two page states, and re-picking the same student every time is
  // friction with no purpose.
  var SEL_KEY = 'zenx.sc.student';
  var selEl   = document.getElementById('scStudent');

  // Make the accounting consequence visible where the decision is made. A
  // structural head credits its own revenue account, so a lost book booked to
  // 'Tuition Fee' overstates tuition income — balanced, and wrong.
  var headEl = document.getElementById('scHead');
  if (headEl) headEl.addEventListener('change', function () {
    var opt  = headEl.options[headEl.selectedIndex];
    var warn = document.getElementById('scHeadWarn');
    if (opt && opt.getAttribute('data-structural') === '1') {
      warn.textContent = 'This is a fee-structure head. The charge will credit that '
        + 'head\u2019s own revenue account rather than Miscellaneous Income, which '
        + 'overstates fee income for a one-off recovery.';
      warn.style.display = 'block';
    } else {
      warn.style.display = 'none';
    }
  });

  selEl.addEventListener('change', function () {
    msg('');
    try { localStorage.setItem(SEL_KEY, selEl.value); } catch (e) { /* private mode */ }
    load();
  });

  // Restore on arrival. A stored id that is no longer in the list (student
  // withdrawn, different tenant) simply does not match any option and the
  // select stays on the placeholder — no error, no stale selection.
  try {
    var remembered = localStorage.getItem(SEL_KEY);
    if (remembered) {
      selEl.value = remembered;
      if (selEl.value === remembered) load();
    }
  } catch (e) { /* storage unavailable — the page still works */ }

  document.getElementById('scRaise').addEventListener('click', function () {
    var btn = this;
    var body = {
      student_id: document.getElementById('scStudent').value,
      fee_head:   document.getElementById('scHead').value.trim(),
      reason:     document.getElementById('scReason').value.trim(),
      amount:     document.getElementById('scAmount').value
    };
    if (!body.student_id) { msg('Select a student first.', false); return; }

    btn.disabled = true;
    document.getElementById('scMsg').textContent = 'Raising…';
    post('fee_management/raise_student_charge', body)
      .then(function (d) {
        msg('Charge ' + (d.chargeId || '') + ' raised. Demand ' + (d.demandId || '') + '.', true);
        // Reason and amount are per-charge; the HEAD usually is not. Clearing
        // it made raising three breakage charges a three-times-repeated
        // selection.
        document.getElementById('scReason').value = '';
        document.getElementById('scAmount').value = '';
        document.getElementById('scReason').focus();
        load();
      })
      .catch(function (e) { msg(e.message, false); })
      .then(function () { btn.disabled = false; });
  });

  document.getElementById('scRows').addEventListener('click', function (ev) {
    var b = ev.target.closest('.sc-waive');
    if (!b) return;
    var reason = window.prompt('Why is this charge being waived? The family sees this.');
    if (!reason) return;
    b.disabled = true;
    post('fee_management/waive_student_charge', { charge_id: b.dataset.id, reason: reason })
      .then(function () { msg('Charge waived.', true); load(); })
      .catch(function (e) { msg(e.message, false); b.disabled = false; });
  });
});
</script>
