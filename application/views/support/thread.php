<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
/* ── Support Desk · Thread (P1 read + P2 write) ───────────────────────────────
   Reachable by the ticket's assignee OR anyone holding 'Support' at view.
   Support::thread() has already enforced that; these flags only decide which
   controls render. Every endpoint re-checks server-side, so a flag that is
   wrong here produces a refused request, never an unauthorised write.

   NOTE on the two "manage" actions: assign and close are manage-ONLY. Being
   the assignee does not grant them — you cannot reassign a ticket away from
   yourself or close it without the module. See _load_for_write()'s
   $assigneeMayAct flag. */
$can_edit   = isset($can_edit)   ? $can_edit   : false;   // reply / resolve
$can_manage = isset($can_manage) ? $can_manage : false;   // assign / close
$has_queue  = isset($has_queue)  ? $has_queue  : false;   // notes / queue link
$ticket_id  = isset($ticket_id)  ? $ticket_id  : '';
?>
<link rel="stylesheet" href="<?= base_url('assets/css/rbac_ui_kit.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/css/support_desk.css') ?>">

<div class="content-wrapper">
<div class="rx-loadbar" id="rxLoadbar"></div>
<section class="content">
<div class="sd-wrap">
  <div class="sd-head">
    <h1 class="sd-title" id="sdTitle">Ticket</h1>
    <a class="sd-btn" href="<?= base_url($has_queue ? 'support' : 'support/mine') ?>">
      <?= $has_queue ? 'Back to queue' : 'Back to my tickets' ?>
    </a>
  </div>
  <p class="sd-sub" id="sdSub">Loading…</p>

  <div id="sdFlash" class="sd-flash" hidden role="status" aria-live="polite"></div>

  <div class="sd-thread">
    <div>
      <div class="sd-panel" id="sdAttachPanel" style="margin-bottom:18px" hidden>
        <h2>Attachments from the parent</h2>
        <div class="sd-attach" id="sdAttach"></div>
      </div>

      <div class="sd-panel">
        <h2>Conversation</h2>
        <div id="sdMsgs"></div>
        <div id="sdMsgState" class="sd-state">Loading…</div>
      </div>

      <?php if ($can_edit): ?>
      <div class="sd-panel sd-compose" id="sdReplyPanel" style="margin-top:18px" hidden>
        <h2>Reply to the parent</h2>
        <textarea id="sdReplyBody" rows="4" placeholder="This is sent to the parent."
                  aria-label="Reply to the parent"></textarea>
        <div class="sd-actions">
          <button class="sd-btn sd-primary" id="sdReplyBtn">Send reply</button>
          <button class="sd-btn" id="sdResolveBtn" title="Sends this message and marks the ticket resolved">
            Send &amp; resolve
          </button>
        </div>
      </div>
      <?php endif; ?>

      <?php if ($has_queue): ?>
      <div class="sd-panel sd-compose" style="margin-top:18px">
        <h2>Internal note — not visible to the parent</h2>
        <textarea id="sdNoteBody" rows="3"
                  placeholder="Context for colleagues. The parent never sees this."
                  aria-label="Internal note"></textarea>
        <div class="sd-actions">
          <button class="sd-btn" id="sdNoteBtn">Add note</button>
        </div>
      </div>

      <div class="sd-panel" id="sdNotesPanel" style="margin-top:18px" hidden>
        <h2>Notes</h2>
        <div id="sdNotes"></div>
      </div>
      <?php endif; ?>
    </div>

    <aside>
      <div class="sd-panel">
        <h2>Details</h2>
        <dl class="sd-facts" id="sdFacts"></dl>
      </div>

      <div class="sd-panel" id="sdActionPanel" style="margin-top:18px" hidden>
        <h2>Actions</h2>

        <?php if ($can_manage): ?>
        <label class="sd-lbl" for="sdAssignee">Assign to</label>
        <select id="sdAssignee" class="sd-select" aria-label="Assign to staff member">
          <option value="">Loading staff…</option>
        </select>
        <button class="sd-btn sd-full" id="sdAssignBtn">Assign</button>
        <?php endif; ?>

        <button class="sd-btn sd-full" id="sdReturnBtn" hidden
                title="Hand this back to the queue for re-triage">Return to queue</button>

        <?php if ($can_manage): ?>
        <button class="sd-btn sd-full sd-danger" id="sdCloseBtn">Force close</button>
        <?php endif; ?>
      </div>
    </aside>
  </div>
</div>
</section>
</div>

<script src="<?= base_url('assets/js/support_desk.js') ?>"></script>
<script>
(function () {
  'use strict';
  SD.base     = '<?= rtrim(base_url(), "/") ?>';
  SD.csrfName = '<?= $this->security->get_csrf_token_name() ?>';
  SD.csrfHash = '<?= $this->security->get_csrf_hash() ?>';

  var TICKET_ID  = '<?= html_escape($ticket_id) ?>';
  var CAN_EDIT   = <?= $can_edit   ? 'true' : 'false' ?>;
  var CAN_MANAGE = <?= $can_manage ? 'true' : 'false' ?>;
  var HAS_QUEUE  = <?= $has_queue  ? 'true' : 'false' ?>;

  var $msgs  = document.getElementById('sdMsgs');
  var $mstat = document.getElementById('sdMsgState');
  var $notes = document.getElementById('sdNotes');
  var $npanel= document.getElementById('sdNotesPanel');
  var $facts = document.getElementById('sdFacts');
  var $flash = document.getElementById('sdFlash');
  var $apanel= document.getElementById('sdActionPanel');

  var isAssignee = false;

  function flash(msg, kind) {
    $flash.hidden = false;
    $flash.className = 'sd-flash ' + (kind === 'error' ? 'sd-flash-err' : 'sd-flash-ok');
    $flash.textContent = msg;              // textContent — never innerHTML for server text
    if (kind !== 'error') setTimeout(function () { $flash.hidden = true; }, 4000);
  }

  function sideClass(t) {
    return t === 'parent' ? 'sd-parent' : (t === 'system' ? 'sd-system' : 'sd-staff');
  }

  /** Every value below is escaped — bodies are parent-supplied free text. */
  function msgHTML(m) {
    var who = m.senderName || (m.senderType === 'parent' ? 'Parent' :
              (m.senderType === 'system' ? 'System' : 'School'));
    var n = (m.attachments && m.attachments.length) || 0;
    var att = n ? '<div class="sd-meta">' + n + ' attachment' + (n === 1 ? '' : 's') + '</div>' : '';
    return '<div class="sd-msg">' +
      '<div class="sd-msg-hd">' +
        '<span class="sd-who">' + SD.esc(who) + '</span>' +
        '<span class="sd-side ' + sideClass(m.senderType) + '">' + SD.esc(m.senderType || '—') + '</span>' +
        '<span class="sd-time">' + SD.esc(SD.when(m.createdAt)) + '</span>' +
      '</div>' +
      '<div class="sd-body">' + SD.esc(m.body || '') + '</div>' + att +
    '</div>';
  }

  function noteHTML(n) {
    return '<div class="sd-note">' +
      '<div class="sd-msg-hd">' +
        '<span class="sd-who">' + SD.esc(n.authorName || 'Staff') + '</span>' +
        '<span class="sd-time">' + SD.esc(SD.when(n.createdAt)) + '</span>' +
      '</div>' +
      '<div class="sd-body">' + SD.esc(n.body || '') + '</div>' +
    '</div>';
  }

  /**
   * One detail row.
   *
   * ⚠ `value` is written as HTML, so every caller below passes either a static
   * literal, SD.pill() output, or an SD.esc()'d string. Never hand it a raw
   * field off the ticket — reporterName and studentName are parent-supplied.
   */
  function fact(label, value) {
    return '<dt>' + SD.esc(label) + '</dt><dd>' + value + '</dd>';
  }

  function render(d) {
    var t = d.ticket || {};
    isAssignee = !!d.is_assignee;

    document.getElementById('sdTitle').textContent =
      (t.ticketNo ? '#' + t.ticketNo + ' · ' : '') + (t.subject || 'Ticket');
    document.getElementById('sdSub').textContent =
      (t.category || 'Uncategorised') + ' · raised ' + SD.when(t.createdAt);

    // Attachments. The href carries a ticket id and a FILENAME, never a path —
    // the server rebuilds the storage path from the ticket it just authorised
    // and refuses any name that ticket does not declare. See Support::attachment.
    var names = d.attachmentNames || [];
    if (names.length) {
      // Reveal the panel BEFORE writing the images, and do NOT lazy-load them.
      //
      // Both halves matter. These <img> were injected into a container that was
      // still `hidden`, so Chrome never scheduled the lazy load — and un-hiding
      // afterwards does not retrigger it. The tile then stayed blank forever:
      // verified 2026-08-30, still incomplete after 18s, while the identical URL
      // loaded in ~3s via a script-created Image(). Staff simply never saw that a
      // parent had attached a photo.
      //
      // `loading="lazy"` bought nothing regardless: at most 3 thumbnails, always
      // at the top of the thread and always in view.
      document.getElementById('sdAttachPanel').hidden = false;
      document.getElementById('sdAttach').innerHTML = names.map(function (n) {
        var url = SD.base + '/support/attachment/' +
                  encodeURIComponent(t.ticketId) + '/' + encodeURIComponent(n);
        return '<a class="sd-thumb" href="' + url + '" target="_blank" rel="noopener noreferrer">' +
                 '<img src="' + url + '" alt="' + SD.esc(n) + '">' +
               '</a>';
      }).join('');
    }

    var msgs = d.messages || [];
    if (msgs.length) { $msgs.innerHTML = msgs.map(msgHTML).join(''); $mstat.hidden = true; }
    else { $mstat.className = 'sd-state'; $mstat.innerHTML = '<b>No messages yet</b>The thread is empty.'; }

    if ($npanel && d.can_note && (d.notes || []).length) {
      $notes.innerHTML = d.notes.map(noteHTML).join('');
      $npanel.hidden = false;
    }

    var html = '';
    html += fact('Status', SD.pill(t.status));
    if (t.awaitingUs) html += fact('Waiting on', '<span class="sd-pill sd-await">us</span>');
    html += fact('Assignee', t.assignedName ? SD.esc(t.assignedName) : '<span class="sd-meta">Unassigned</span>');
    html += fact('Raised by', t.isAnonymous ? '<em>withheld</em>' : SD.esc(t.reporterName || '—'));
    html += fact('Student', t.isAnonymous ? '<em>withheld</em>'
                 : SD.esc((t.studentName || '—') + (t.className ? ' · ' + t.className : '')));
    html += fact('Session', SD.esc(t.sessionId || '—'));
    html += fact('Last activity', SD.esc(SD.when(t.lastMessageAt)));
    if (t.resolvedAt)    html += fact('Resolved', SD.esc(SD.when(t.resolvedAt)));
    if (t.closureReason) html += fact('Closure reason', SD.esc(t.closureReason));
    $facts.innerHTML = html;

    var closed = t.status === 'closed';

    // Reply composer: hidden on a closed ticket, because the server refuses it
    // and offering a box that always fails is worse than offering none.
    var rp = document.getElementById('sdReplyPanel');
    if (rp) rp.hidden = !(CAN_EDIT && !closed);

    // Return-to-queue is the assignee's escape hatch and nobody else's.
    var rb = document.getElementById('sdReturnBtn');
    if (rb) rb.hidden = !(isAssignee && t.assignedTo && !closed);

    if ($apanel) $apanel.hidden = !(CAN_MANAGE || (rb && !rb.hidden));
    if (CAN_MANAGE) loadAssignees(t.assignedTo);
  }

  function reload() {
    return SD.getJSON(SD.base + '/support/get_thread/' + encodeURIComponent(TICKET_ID))
      .then(render)
      .catch(function (e) {
        // A failure must not render as an empty thread.
        //
        // W3: render() hides $mstat once messages draw, and this block set its
        // class and innerHTML but never un-hid it — so on a RELOAD failure the
        // error was written into an invisible element. The visible result was a
        // green "Reply sent." flash, a blank Details sidebar, no subtitle, the
        // previous conversation still on screen, and the action panel still
        // enabled against unknown ticket state. The list views get this right
        // (support_desk.js sets state.hidden = false first); this inline copy
        // lost the line.
        $mstat.hidden = false;
        $mstat.className = 'sd-state sd-err';
        $mstat.innerHTML = '<b>Could not load this ticket</b>' + SD.esc(e.message);
        $facts.innerHTML = '';
        document.getElementById('sdSub').textContent = '';
        // The panels below act on a ticket whose state we no longer know.
        ['sdActionPanel', 'sdReplyPanel'].forEach(function (id) {
          var el = document.getElementById(id);
          if (el) el.hidden = true;
        });
      });
  }

  function loadAssignees(current) {
    var sel = document.getElementById('sdAssignee');
    if (!sel) return;
    SD.getJSON(SD.base + '/support/get_assignees').then(function (d) {
      var opts = ['<option value="">Choose a staff member…</option>'];
      (d.staff || []).forEach(function (s) {
        opts.push('<option value="' + SD.esc(s.staffId) + '"' +
          (s.staffId === current ? ' selected' : '') + '>' +
          SD.esc(s.name) + (s.department ? ' — ' + SD.esc(s.department) : '') + '</option>');
      });
      sel.innerHTML = opts.join('');
    }).catch(function (e) {
      sel.innerHTML = '<option value="">Could not load staff</option>';
      flash(e.message, 'error');
    });
  }

  /** Wrap a write: disable the button, post, reload, report honestly. */
  // Returns a promise resolving TRUE only if the write actually succeeded.
  //
  // It used to return nothing, so every caller cleared its textarea on the line
  // AFTER the call — synchronously, before the POST had resolved. A staff member
  // who wrote a long reply and hit a 403, a 409 (someone else closed the ticket),
  // a 500, or an expired session got an honest error message with the box
  // already empty. Accurate error, work gone: they were told to retry something
  // that no longer existed.
  function act(btn, url, fields, okMsg) {
    if (!btn) return Promise.resolve(false);
    btn.disabled = true;
    var was = btn.textContent;
    btn.textContent = 'Working…';
    return SD.postJSON(SD.base + url, fields).then(function () {
      flash(okMsg, 'ok');
      // The write succeeded even if the redraw afterwards does not.
      return reload().then(function () { return true; }, function () { return true; });
    }).catch(function (e) {
      // Never claim success on a refused write.
      flash(e.message, 'error');
      return false;
    }).then(function (ok) {
      btn.disabled = false;
      btn.textContent = was;
      return ok;
    });
  }

  /** Clear a field only once the write that consumed it actually succeeded. */
  function clearOnOk(promise, elId) {
    return promise.then(function (ok) {
      if (ok) { var el = document.getElementById(elId); if (el) el.value = ''; }
      return ok;
    });
  }

  var replyBtn = document.getElementById('sdReplyBtn');
  if (replyBtn) replyBtn.addEventListener('click', function () {
    var body = document.getElementById('sdReplyBody').value.trim();
    if (!body) { flash('Write a reply first.', 'error'); return; }
    clearOnOk(act(this, '/support/reply', { ticket_id: TICKET_ID, body: body }, 'Reply sent.'), 'sdReplyBody');
  });

  var resolveBtn = document.getElementById('sdResolveBtn');
  if (resolveBtn) resolveBtn.addEventListener('click', function () {
    var body = document.getElementById('sdReplyBody').value.trim();
    if (!body) { flash('Write a closing message — "resolved" with no explanation is the main cause of reopens.', 'error'); return; }
    clearOnOk(act(this, '/support/resolve', { ticket_id: TICKET_ID, body: body }, 'Resolved.'), 'sdReplyBody');
  });

  var noteBtn = document.getElementById('sdNoteBtn');
  if (noteBtn) noteBtn.addEventListener('click', function () {
    var body = document.getElementById('sdNoteBody').value.trim();
    if (!body) { flash('Write a note first.', 'error'); return; }
    clearOnOk(act(this, '/support/add_note', { ticket_id: TICKET_ID, body: body }, 'Note added.'), 'sdNoteBody');
  });

  var assignBtn = document.getElementById('sdAssignBtn');
  if (assignBtn) assignBtn.addEventListener('click', function () {
    var id = document.getElementById('sdAssignee').value;
    if (!id) { flash('Pick a staff member first.', 'error'); return; }
    act(this, '/support/assign', { ticket_id: TICKET_ID, staff_id: id }, 'Assigned.');
  });

  var returnBtn = document.getElementById('sdReturnBtn');
  if (returnBtn) returnBtn.addEventListener('click', function () {
    // Mandatory, and the only signal an admin gets that their routing was wrong.
    var reason = window.prompt('Why are you returning this to the queue?');
    if (reason === null) return;
    if (!reason.trim()) { flash('A reason is required.', 'error'); return; }
    act(this, '/support/return_to_queue', { ticket_id: TICKET_ID, reason: reason.trim() }, 'Returned to the queue.');
  });

  var closeBtn = document.getElementById('sdCloseBtn');
  if (closeBtn) closeBtn.addEventListener('click', function () {
    var reason = window.prompt('Reason for closing this ticket? This is recorded.');
    if (reason === null) return;
    if (!reason.trim()) { flash('A reason is required.', 'error'); return; }
    act(this, '/support/force_close', { ticket_id: TICKET_ID, reason: reason.trim() }, 'Ticket closed.');
  });

  reload();
})();
</script>
