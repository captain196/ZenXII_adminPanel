<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
/* ── Support Desk · Queue (P1, read-only) ─────────────────────────────────────
   RBAC level flags. The server re-enforces every gate; these only keep the UI
   honest so it never renders a control the server would refuse.
     edit   → reply, resolve assigned      (P2)
     manage → assign / reassign / close    (P2)
   Recomputed from has_permission here so the exact required level is mirrored,
   matching the pattern in views/red_flags/index.php. */
$can_edit   = isset($can_edit)   ? $can_edit   : (function_exists('has_permission') ? has_permission('Support', 'edit')   : false);
$can_manage = isset($can_manage) ? $can_manage : (function_exists('has_permission') ? has_permission('Support', 'manage') : false);
?>
<link rel="stylesheet" href="<?= base_url('assets/css/rbac_ui_kit.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/css/support_desk.css') ?>">

<div class="sd-wrap">
  <div class="sd-head"><h1 class="sd-title">Support</h1></div>
  <p class="sd-sub">Tickets raised by parents. Triage, assign, and reply.</p>

  <div class="sd-bar">
    <div class="sd-tabs" role="tablist" aria-label="Ticket status">
      <button class="sd-tab" role="tab" aria-selected="true"  data-status="active">Active</button>
      <button class="sd-tab" role="tab" aria-selected="false" data-status="open">Open</button>
      <button class="sd-tab" role="tab" aria-selected="false" data-status="assigned">Assigned</button>
      <button class="sd-tab" role="tab" aria-selected="false" data-status="reopened">Reopened</button>
      <button class="sd-tab" role="tab" aria-selected="false" data-status="resolved">Resolved</button>
      <button class="sd-tab" role="tab" aria-selected="false" data-status="closed">Closed</button>
      <button class="sd-tab" role="tab" aria-selected="false" data-status="all">All</button>
    </div>
    <span class="sd-spacer"></span>
    <button class="sd-btn sd-toggle" id="sdAwait" aria-pressed="false"
            title="Only tickets where the parent replied last">Awaiting us</button>
    <form class="sd-search" id="sdSearchForm" role="search" onsubmit="return false;">
      <input type="search" id="sdQ" placeholder="Ticket no, name, word…" aria-label="Search tickets">
      <button class="sd-btn" type="submit">Search</button>
    </form>
  </div>

  <div class="sd-tablewrap">
    <table class="sd-table">
      <thead>
        <tr>
          <th>Ticket</th><th>Subject</th><th>Category</th><th>Student</th>
          <th>Status</th><th>Assignee</th><th>Last activity</th>
        </tr>
      </thead>
      <tbody id="sdRows"></tbody>
    </table>
    <div id="sdState" class="sd-state">Loading…</div>
    <div class="sd-more" id="sdMoreWrap" hidden><button class="sd-btn" id="sdMore">Load more</button></div>
  </div>
</div>

<script src="<?= base_url('assets/js/support_desk.js') ?>"></script>
<script>
(function () {
  'use strict';
  SD.base = '<?= rtrim(base_url(), "/") ?>';

  var q = '', status = 'active', awaiting = false;

  var list = SD.mountList({
    rows:     document.getElementById('sdRows'),
    state:    document.getElementById('sdState'),
    more:     document.getElementById('sdMore'),
    moreWrap: document.getElementById('sdMoreWrap'),

    url: function (cursor) {
      var p = new URLSearchParams();
      if (q) { p.set('q', q); return SD.base + '/support/search?' + p.toString(); }
      p.set('status', status);
      if (awaiting) p.set('filter', 'awaiting');
      if (cursor)   p.set('cursor', cursor);
      return SD.base + '/support/get_queue?' + p.toString();
    },

    // Escaped at the call site — see the contract on SD.mountList.
    empty: function () {
      return q
        ? '<b>Nothing matched “' + SD.esc(q) + '”</b>Search covers ticket number, student name, and words in the subject.'
        : '<b>No tickets here</b>Nothing in this view right now.';
    }
  });

  Array.prototype.forEach.call(document.querySelectorAll('.sd-tab'), function (tab) {
    tab.addEventListener('click', function () {
      Array.prototype.forEach.call(document.querySelectorAll('.sd-tab'), function (t) {
        t.setAttribute('aria-selected', String(t === tab));
      });
      status = tab.getAttribute('data-status');
      q = '';
      document.getElementById('sdQ').value = '';
      list.reload();
    });
  });

  document.getElementById('sdAwait').addEventListener('click', function () {
    awaiting = !awaiting;
    this.setAttribute('aria-pressed', String(awaiting));
    list.reload();
  });

  document.getElementById('sdSearchForm').addEventListener('submit', function () {
    q = document.getElementById('sdQ').value.trim();
    list.reload();
    return false;
  });

  list.reload();
})();
</script>
