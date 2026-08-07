<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<!--
  transport/partials/route_suspension_list.php
  F2 Route & Stop Management (2026-07-07)

  Active suspensions banner card. Rendered on the Routes & Stops tab
  above the routes card so operators see the operational picture first.
  Populated by TRN.loadActiveSuspensions() (see transport/index.php).
-->
<div class="trn-card" id="activeSuspensionsCard" style="border-left:4px solid var(--rose);display:none">
  <div class="trn-card-title">
    <span><i class="fa fa-exclamation-circle" style="color:var(--rose)"></i> Active Route Suspensions <span id="activeSuspCount" style="color:var(--t3);font-weight:400"></span></span>
  </div>
  <table class="trn-table"><thead><tr>
    <th>Suspension</th><th>Route</th><th>Category</th><th>From</th><th>Reason</th><th>Parents?</th><th>Actions</th>
  </tr></thead><tbody id="activeSuspTbody"></tbody></table>
</div>
