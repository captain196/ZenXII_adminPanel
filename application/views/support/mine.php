<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<?php
/* ── Support Desk · My Tickets (P1, read-only) ────────────────────────────────
   This screen is reachable by ANY authenticated staff member — there is no
   'Support' module check, deliberately. Any staff member can be handed a
   ticket, so gating this page on the module is exactly what let an admin
   assign work to somebody who then could not see it. See Support::mine().

   $has_queue tells us whether this user ALSO holds the module, which decides
   whether we offer a link back to the full queue. Absence of it is normal, not
   an error state. */
$has_queue = isset($has_queue) ? $has_queue : (function_exists('has_permission') ? has_permission('Support', 'view') : false);
?>
<link rel="stylesheet" href="<?= base_url('assets/css/rbac_ui_kit.css') ?>">
<link rel="stylesheet" href="<?= base_url('assets/css/support_desk.css') ?>">

<div class="content-wrapper">
<div class="rx-loadbar" id="rxLoadbar"></div>
<section class="content">
<div class="sd-wrap">
  <div class="sd-head">
    <h1 class="sd-title">My Tickets</h1>
    <?php if ($has_queue): ?>
      <a class="sd-btn" href="<?= base_url('support') ?>">Whole queue</a>
    <?php endif; ?>
  </div>
  <p class="sd-sub">Support tickets assigned to you.</p>

  <div class="sd-bar">
    <div class="sd-tabs" role="tablist" aria-label="Ticket status">
      <button class="sd-tab" role="tab" aria-selected="true"  data-status="active">Active</button>
      <button class="sd-tab" role="tab" aria-selected="false" data-status="resolved">Resolved</button>
      <button class="sd-tab" role="tab" aria-selected="false" data-status="closed">Closed</button>
      <button class="sd-tab" role="tab" aria-selected="false" data-status="all">All</button>
    </div>
  </div>

  <div class="sd-tablewrap">
    <table class="sd-table">
      <thead>
        <tr>
          <th>Ticket</th><th>Subject</th><th>Category</th><th>Student</th>
          <th>Status</th><th>Last activity</th>
        </tr>
      </thead>
      <tbody id="sdRows"></tbody>
    </table>
    <div id="sdState" class="sd-state">Loading…</div>
    <div class="sd-more" id="sdMoreWrap" hidden><button class="sd-btn" id="sdMore">Load more</button></div>
  </div>
</div>
</section>
</div>

<script src="<?= base_url('assets/js/support_desk.js') ?>"></script>
<script>
(function () {
  'use strict';
  SD.base = '<?= rtrim(base_url(), "/") ?>';

  var status = 'active';

  var list = SD.mountList({
    rows:     document.getElementById('sdRows'),
    state:    document.getElementById('sdState'),
    more:     document.getElementById('sdMore'),
    moreWrap: document.getElementById('sdMoreWrap'),

    // The assignee column is always you here, so it is omitted. The table
    // header above has one fewer column to match.
    showAssignee: false,

    url: function (cursor) {
      var p = new URLSearchParams();
      p.set('status', status);
      if (cursor) p.set('cursor', cursor);
      return SD.base + '/support/get_mine?' + p.toString();
    },

    empty: function () {
      return status === 'active'
        ? '<b>Nothing assigned to you</b>Tickets an admin assigns to you will appear here.'
        : '<b>Nothing here</b>No tickets in this view.';
    }
  });

  Array.prototype.forEach.call(document.querySelectorAll('.sd-tab'), function (tab) {
    tab.addEventListener('click', function () {
      Array.prototype.forEach.call(document.querySelectorAll('.sd-tab'), function (t) {
        t.setAttribute('aria-selected', String(t === tab));
      });
      status = tab.getAttribute('data-status');
      list.reload();
    });
  });

  list.reload();
})();
</script>
