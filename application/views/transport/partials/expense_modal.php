<?php defined('BASEPATH') or exit('No direct script access allowed'); ?>
<?php
// 2026-08-07 — graded RBAC (TL convention, commit 7ccebc0). Recomputed locally:
// this partial is loaded via $this->load->view() with no data, so the parent
// view's flags are NOT in scope. The server remains authoritative — every
// endpoint re-checks via require_permission('Transport', <action>, <level>).
$can_edit   = function_exists('has_permission') ? has_permission('Transport','edit')   : true;
$can_manage = function_exists('has_permission') ? has_permission('Transport','manage') : true;
?>
<!--
  transport/partials/expense_modal.php
  F5 Transport Expenses + Accounting (2026-07-07)

  Expense save modal. Supports Refinement additional: generic
  attachments (not receipt-only) — Receipt / Invoice / Quotation /
  Workshop_Estimate / Warranty / Approval_Letter / Other.
-->
<div class="trn-modal-bg" id="expenseModal"><div class="trn-modal" style="width:780px;max-height:90vh">
  <div class="trn-modal-title">
    <span id="expModalTitle">Record Expense</span>
    <button class="trn-modal-close" onclick="TRN.closeExpense()">&times;</button>
  </div>
  <input type="hidden" id="expId">

  <div class="trn-form-row">
    <div class="trn-form-group"><label>Expense Type *</label>
      <select id="expType" onchange="TRN.expTypeChanged()"></select>
    </div>
    <div class="trn-form-group"><label>Expense Date *</label><input type="date" id="expDate"></div>
  </div>

  <div class="trn-form-group" id="expCustomTypeRow" style="display:none">
    <label>Custom Type Description *</label>
    <input type="text" id="expCustomType" placeholder="Describe the expense">
  </div>

  <div class="trn-form-row">
    <div class="trn-form-group"><label>Amount (INR) *</label><input type="number" id="expAmount" min="0" step="0.01"></div>
    <div class="trn-form-group"><label>Tax Amount</label><input type="number" id="expTaxAmt" min="0" step="0.01" value="0"></div>
  </div>
  <div class="trn-form-row">
    <div class="trn-form-group"><label>Tax Rate %</label><input type="number" id="expTaxRate" min="0" max="100" step="0.01" value="0"></div>
    <div class="trn-form-group"><label>Payment Method *</label>
      <select id="expPayMethod"></select>
    </div>
  </div>

  <div class="trn-form-row">
    <div class="trn-form-group"><label>Payee Type *</label>
      <select id="expPayeeType" onchange="TRN.expPayeeTypeChanged()"></select>
    </div>
    <div class="trn-form-group"><label>Payee ID</label><input type="text" id="expPayeeId" placeholder="VND0001 / STA0007 / blank"></div>
  </div>
  <div class="trn-form-group"><label>Payee Name</label><input type="text" id="expPayeeName"></div>

  <div class="trn-form-row">
    <div class="trn-form-group"><label>Vehicle (optional)</label>
      <select id="expVehicle"><option value="">-- None --</option></select>
    </div>
    <div class="trn-form-group"><label>Payment Reference</label><input type="text" id="expPayRef" placeholder="Cheque/TXN no."></div>
  </div>

  <div class="trn-form-row">
    <div class="trn-form-group"><label>Related Maintenance ID</label><input type="text" id="expRelMnt" placeholder="MNT000001"></div>
    <div class="trn-form-group"><label>Related Fuel ID</label><input type="text" id="expRelFuel" placeholder="FUEL000001"></div>
  </div>

  <div class="trn-form-group"><label>Notes</label><input type="text" id="expNotes"></div>

  <!-- Generic attachments (Refinement additional 2026-07-07) -->
  <div class="trn-form-group">
    <label>Attachments <small style="color:var(--t3)">— Receipt / Invoice / Quotation / Workshop Estimate / Warranty / Approval Letter / Other</small></label>
    <div id="expAttachments"></div>
    <button type="button" class="trn-btn trn-btn-sm trn-btn-primary" onclick="TRN.expAddAttachment()"><i class="fa fa-plus"></i> Add Attachment</button>
  </div>

  <div style="background:rgba(59,130,246,.08);border-left:3px solid #3b82f6;padding:10px 12px;border-radius:4px;font-size:12px;color:var(--t1);font-family:var(--font-b);margin-bottom:14px">
    <b>Posting policy:</b> reads from schoolControl.transport.expense_posting_mode (default 'manual'). Tick "Post Now" to force immediate posting regardless of policy.
  </div>
  <div class="trn-form-group">
    <label><input type="checkbox" id="expForcePost"> Force Post to Accounting Now</label>
  </div>

  <button class="trn-btn trn-btn-primary" onclick="TRN.saveExpense()" <?= $can_edit?'':'disabled' ?> style="width:100%">Save Expense</button>
</div></div>

<!-- Void Expense Modal -->
<div class="trn-modal-bg" id="expVoidModal"><div class="trn-modal" style="width:440px">
  <div class="trn-modal-title">
    <span>Void Expense</span>
    <button class="trn-modal-close" onclick="TRN.closeExpVoid()">&times;</button>
  </div>
  <input type="hidden" id="expVoidId">
  <div class="trn-form-group"><label>Reason *</label><input type="text" id="expVoidReason" placeholder="e.g. Duplicate entry"></div>
  <button class="trn-btn trn-btn-danger" onclick="TRN.confirmVoidExpense()" <?= $can_manage?'':'disabled' ?> style="width:100%">Void</button>
</div></div>
