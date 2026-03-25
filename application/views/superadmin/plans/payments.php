<?php
$schools = $schools ?? [];
$plans   = $plans   ?? [];
?>

<section class="content-header">
    <h1><i class="fa fa-money" style="color:var(--sa3);margin-right:10px;font-size:20px;"></i>Billing &amp; Payments</h1>
    <ol class="breadcrumb">
        <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('superadmin/plans') ?>">Plans</a></li>
        <li class="active">Payments</li>
    </ol>
</section>

<section class="content" style="padding:20px 24px;">

    <!-- Quick-nav -->
    <div style="display:flex;gap:8px;margin-bottom:20px;align-items:center;flex-wrap:wrap;">
        <a href="<?= base_url('superadmin/plans') ?>" class="btn btn-default btn-sm"><i class="fa fa-tags"></i> Plans</a>
        <a href="<?= base_url('superadmin/plans/subscriptions') ?>" class="btn btn-default btn-sm"><i class="fa fa-calendar-check-o"></i> Subscriptions</a>
        <a href="<?= base_url('superadmin/plans/payments') ?>" class="btn btn-primary btn-sm"><i class="fa fa-money"></i> Billing</a>
        <div style="margin-left:auto;display:flex;gap:8px;">
            <button class="btn btn-info btn-sm" id="genInvoiceBtn"><i class="fa fa-file-text-o"></i> Generate Invoice</button>
            <button class="btn btn-success btn-sm" id="addPaymentBtn"><i class="fa fa-plus"></i> New Invoice</button>
        </div>
    </div>

    <!-- KPI cards -->
    <div style="display:flex;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
        <?php
        $kpis = [
            ['id'=>'kpi_total',   'label'=>'Invoices',           'icon'=>'fa-file-text-o',          'color'=>'var(--sa3)'],
            ['id'=>'kpi_revenue', 'label'=>'Total Collected',    'icon'=>'fa-inr',                  'color'=>'#22c55e'],
            ['id'=>'kpi_balance', 'label'=>'Outstanding',        'icon'=>'fa-exclamation-circle',   'color'=>'#ef4444'],
            ['id'=>'kpi_overdue', 'label'=>'Overdue',            'icon'=>'fa-exclamation-triangle', 'color'=>'#dc2626'],
            ['id'=>'kpi_partial', 'label'=>'Partially Paid',     'icon'=>'fa-adjust',               'color'=>'#f97316'],
            ['id'=>'kpi_paid',    'label'=>'Fully Paid',         'icon'=>'fa-check-circle',         'color'=>'#16a34a'],
            ['id'=>'kpi_pending', 'label'=>'Pending',            'icon'=>'fa-clock-o',              'color'=>'#2563eb'],
        ];
        foreach($kpis as $k): ?>
        <div style="flex:1;min-width:120px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:14px 12px;text-align:center;">
                <i class="fa <?= $k['icon'] ?>" style="font-size:18px;color:<?= $k['color'] ?>;margin-bottom:4px;display:block;"></i>
                <div id="<?= $k['id'] ?>" style="font-size:18px;font-weight:800;color:var(--t1);font-family:var(--font-d);">—</div>
                <div style="font-size:10px;color:var(--t3);font-family:var(--font-m);"><?= $k['label'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div style="display:flex;gap:6px;margin-bottom:14px;flex-wrap:wrap;align-items:center;">
        <button class="btn btn-default btn-sm pay-filter active" data-f="all">All</button>
        <button class="btn btn-default btn-sm pay-filter" data-f="paid"    style="border-color:#22c55e;color:#22c55e;">Paid</button>
        <button class="btn btn-default btn-sm pay-filter" data-f="partial" style="border-color:#f97316;color:#f97316;">Partial</button>
        <button class="btn btn-default btn-sm pay-filter" data-f="pending" style="border-color:#2563eb;color:#2563eb;">Pending</button>
        <button class="btn btn-default btn-sm pay-filter" data-f="overdue" style="border-color:#ef4444;color:#ef4444;">Overdue</button>
        <button class="btn btn-default btn-sm pay-filter" data-f="failed"  style="border-color:#6b7280;color:#6b7280;">Failed</button>
        <div style="margin-left:auto;display:flex;gap:8px;align-items:center;">
            <select id="paySortBy" class="form-control input-sm" style="width:160px;">
                <option value="newest">Newest First</option>
                <option value="due_asc">Due Date (Soonest)</option>
                <option value="balance_desc">Balance (Highest)</option>
                <option value="amount_desc">Invoice Amount</option>
            </select>
            <input type="text" id="paySearch" class="form-control input-sm" placeholder="Search school..." style="width:200px;">
        </div>
    </div>

    <!-- Table -->
    <div class="box" style="border-radius:10px;overflow:hidden;border:1px solid var(--border);">
        <div class="box-body" style="padding:0;overflow-x:auto;">
            <table class="table table-hover" style="margin:0;min-width:1100px;">
                <thead>
                    <tr style="background:var(--bg3);">
                        <?php foreach(['SCHOOL','PLAN / CYCLE','INVOICE','PAID','BALANCE','STATUS','DUE DATE','DAYS','PERIOD','ACTIONS'] as $h): ?>
                        <th style="padding:11px 14px;font-size:10.5px;font-family:var(--font-m);color:var(--t3);border-bottom:1px solid var(--border);white-space:nowrap;text-transform:uppercase;letter-spacing:.5px;"><?= $h ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody id="payTableBody">
                    <tr><td colspan="10" style="text-align:center;padding:40px;color:var(--t3);"><i class="fa fa-spinner fa-spin" style="font-size:20px;"></i><br>Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

</section>

<!-- ═══════════ COLLECT PAYMENT MODAL ═══════════ -->
<div class="modal fade" id="collectModal" tabindex="-1">
    <div class="modal-dialog" style="max-width:500px;">
        <div class="modal-content" style="border-radius:14px;overflow:hidden;border:1px solid var(--border);background:var(--bg2);">
            <div style="padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;">
                <div style="width:40px;height:40px;border-radius:10px;background:rgba(34,197,94,.12);display:flex;align-items:center;justify-content:center;">
                    <i class="fa fa-inr" style="font-size:18px;color:#22c55e;"></i>
                </div>
                <div style="flex:1;">
                    <h4 style="margin:0;font-size:16px;font-weight:700;color:var(--t1);">Collect Payment</h4>
                    <div id="colInvLabel" style="font-size:11.5px;color:var(--t3);font-family:var(--font-m);margin-top:1px;"></div>
                </div>
                <button type="button" class="close" data-dismiss="modal" style="font-size:22px;color:var(--t3);opacity:.7;">&times;</button>
            </div>
            <div class="modal-body" style="padding:20px 24px;">
                <input type="hidden" id="colInvId">
                <!-- Invoice summary -->
                <div style="display:flex;gap:10px;margin-bottom:18px;">
                    <div style="flex:1;background:var(--bg3);border-radius:8px;padding:10px;text-align:center;">
                        <div style="font-size:9px;color:var(--t3);text-transform:uppercase;font-family:var(--font-m);">Invoice</div>
                        <div id="colInvAmt" style="font-size:16px;font-weight:700;color:var(--t1);font-family:var(--font-d);">—</div>
                    </div>
                    <div style="flex:1;background:var(--bg3);border-radius:8px;padding:10px;text-align:center;">
                        <div style="font-size:9px;color:var(--t3);text-transform:uppercase;font-family:var(--font-m);">Paid So Far</div>
                        <div id="colPaidSoFar" style="font-size:16px;font-weight:700;color:#22c55e;font-family:var(--font-d);">—</div>
                    </div>
                    <div style="flex:1;background:var(--bg3);border-radius:8px;padding:10px;text-align:center;">
                        <div style="font-size:9px;color:var(--t3);text-transform:uppercase;font-family:var(--font-m);">Balance Due</div>
                        <div id="colBalance" style="font-size:16px;font-weight:700;color:#ef4444;font-family:var(--font-d);">—</div>
                    </div>
                </div>
                <!-- Previous transactions -->
                <div id="colTxnList" style="margin-bottom:16px;display:none;">
                    <div style="font-size:10px;color:var(--t3);text-transform:uppercase;font-family:var(--font-m);margin-bottom:6px;">Previous Payments</div>
                    <div id="colTxnItems" style="max-height:120px;overflow-y:auto;"></div>
                </div>
                <!-- New payment inputs -->
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group" style="margin-bottom:12px;">
                            <label style="font-size:12px;font-weight:600;color:var(--t2);">Amount (₹) <span style="color:#ef4444;">*</span></label>
                            <input type="number" class="form-control" id="colPayAmt" min="1" step="0.01" style="height:40px;" placeholder="Enter amount...">
                            <div id="colPayHint" style="font-size:11px;color:var(--t3);margin-top:3px;"></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group" style="margin-bottom:12px;">
                            <label style="font-size:12px;font-weight:600;color:var(--t2);">Payment Date</label>
                            <input type="date" class="form-control" id="colPayDate" value="<?= date('Y-m-d') ?>" style="height:40px;">
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-md-6">
                        <div class="form-group" style="margin-bottom:12px;">
                            <label style="font-size:12px;font-weight:600;color:var(--t2);">Payment Mode</label>
                            <select class="form-control" id="colPayMode" style="height:40px;">
                                <option value="Cash">Cash</option>
                                <option value="Bank Transfer" selected>Bank Transfer</option>
                                <option value="UPI">UPI</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Online">Online</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="form-group" style="margin-bottom:12px;">
                            <label style="font-size:12px;font-weight:600;color:var(--t2);">Note</label>
                            <input type="text" class="form-control" id="colPayNote" placeholder="Optional..." style="height:40px;">
                        </div>
                    </div>
                </div>
                <!-- Quick-fill buttons -->
                <div style="display:flex;gap:6px;margin-bottom:6px;">
                    <button class="btn btn-default btn-xs col-quick-fill" data-pct="100">Pay Full Balance</button>
                    <button class="btn btn-default btn-xs col-quick-fill" data-pct="50">50%</button>
                    <button class="btn btn-default btn-xs col-quick-fill" data-pct="25">25%</button>
                </div>
            </div>
            <div style="padding:14px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;">
                <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-sm" id="colSubmitBtn" disabled>
                    <i class="fa fa-check"></i> Record Payment
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════ SCHOOL LEDGER DRAWER ═══════════ -->
<div id="ledgerDrawer" style="display:none;position:fixed;top:0;right:0;bottom:0;width:520px;max-width:96vw;z-index:1050;background:var(--bg2);border-left:1px solid var(--border);box-shadow:-4px 0 24px rgba(0,0,0,.15);overflow-y:auto;">
    <div style="padding:18px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;">
        <i class="fa fa-building" style="color:var(--sa3);font-size:18px;"></i>
        <div style="flex:1;">
            <h4 id="drSchoolName" style="margin:0;font-weight:700;color:var(--t1);font-size:15px;">School</h4>
            <div id="drPlanInfo" style="font-size:11.5px;color:var(--t3);margin-top:2px;"></div>
        </div>
        <button id="closeDrBtn" style="background:none;border:none;font-size:20px;color:var(--t3);cursor:pointer;">&times;</button>
    </div>
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);">
        <div style="display:flex;gap:10px;flex-wrap:wrap;">
            <div style="flex:1;min-width:90px;background:var(--bg3);border-radius:8px;padding:12px;text-align:center;">
                <div style="font-size:9px;color:var(--t3);text-transform:uppercase;font-family:var(--font-m);">Total Billed</div>
                <div id="drBilled" style="font-size:16px;font-weight:800;color:var(--t1);font-family:var(--font-d);">—</div>
            </div>
            <div style="flex:1;min-width:90px;background:var(--bg3);border-radius:8px;padding:12px;text-align:center;">
                <div style="font-size:9px;color:var(--t3);text-transform:uppercase;font-family:var(--font-m);">Collected</div>
                <div id="drPaid" style="font-size:16px;font-weight:800;color:#22c55e;font-family:var(--font-d);">—</div>
            </div>
            <div style="flex:1;min-width:90px;background:var(--bg3);border-radius:8px;padding:12px;text-align:center;">
                <div style="font-size:9px;color:var(--t3);text-transform:uppercase;font-family:var(--font-m);">Balance</div>
                <div id="drBalance" style="font-size:16px;font-weight:800;color:#ef4444;font-family:var(--font-d);">—</div>
            </div>
        </div>
        <div style="margin-top:10px;display:flex;gap:14px;font-size:11.5px;color:var(--t3);font-family:var(--font-m);">
            <span>Expiry: <strong id="drExpiry" style="color:var(--t1);">—</strong></span>
            <span>Status: <strong id="drSubSt" style="color:var(--t1);">—</strong></span>
        </div>
    </div>
    <div style="padding:16px 20px;">
        <h5 style="margin:0 0 12px;font-weight:700;color:var(--t1);font-size:13px;">Invoice History</h5>
        <div id="drTimeline"></div>
    </div>
</div>
<div id="drOverlay" style="display:none;position:fixed;top:0;left:0;right:0;bottom:0;z-index:1049;background:rgba(0,0,0,.3);"></div>

<!-- ═══════════ GENERATE INVOICE MODAL ═══════════ -->
<div class="modal fade" id="genInvModal" tabindex="-1">
    <div class="modal-dialog" style="max-width:460px;">
        <div class="modal-content" style="border-radius:14px;overflow:hidden;border:1px solid var(--border);background:var(--bg2);">
            <div style="padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;">
                <div style="width:40px;height:40px;border-radius:10px;background:rgba(37,99,235,.12);display:flex;align-items:center;justify-content:center;">
                    <i class="fa fa-file-text-o" style="font-size:18px;color:#2563eb;"></i>
                </div>
                <div style="flex:1;">
                    <h4 style="margin:0;font-size:16px;font-weight:700;color:var(--t1);">Generate Invoice</h4>
                    <div style="font-size:11.5px;color:var(--t3);font-family:var(--font-m);">Creates a pending invoice for the next billing period</div>
                </div>
                <button type="button" class="close" data-dismiss="modal" style="font-size:22px;color:var(--t3);opacity:.7;">&times;</button>
            </div>
            <div class="modal-body" style="padding:20px 24px;">
                <div class="form-group" style="margin-bottom:16px;">
                    <label style="font-size:12px;font-weight:600;color:var(--t2);">Select School</label>
                    <select class="form-control" id="giSchool" style="height:40px;">
                        <option value="">-- Select School --</option>
                        <?php foreach($schools as $uid => $s): ?>
                        <option value="<?= htmlspecialchars($uid) ?>"><?= htmlspecialchars($s['name']) ?> <?= !empty($s['school_code']) ? '('.$s['school_code'].')' : '' ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div id="giPreview" style="display:none;padding:14px;background:var(--bg3);border:1px solid var(--border);border-radius:10px;">
                    <div style="font-size:12px;color:var(--t3);font-family:var(--font-m);">
                        <div style="display:flex;justify-content:space-between;padding:5px 0;"><span>Plan:</span><strong id="giPlan" style="color:var(--t1);">—</strong></div>
                        <div style="display:flex;justify-content:space-between;padding:5px 0;"><span>Billing:</span><strong id="giCycle" style="color:var(--t1);">—</strong></div>
                        <div style="display:flex;justify-content:space-between;padding:5px 0;"><span>Invoice Amount:</span><strong id="giAmt" style="color:var(--sa3);font-size:15px;">—</strong></div>
                        <div style="display:flex;justify-content:space-between;padding:5px 0;"><span>Due Date:</span><strong id="giDue" style="color:#2563eb;">—</strong></div>
                        <div style="display:flex;justify-content:space-between;padding:5px 0;"><span>Period:</span><strong id="giPeriod" style="color:var(--t1);">—</strong></div>
                    </div>
                    <div id="giOutstanding" style="display:none;margin-top:10px;padding:8px 12px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:8px;font-size:12px;color:#ef4444;">
                        <i class="fa fa-exclamation-triangle"></i> <span id="giOutMsg"></span>
                    </div>
                </div>
                <div id="giLoading" style="display:none;text-align:center;padding:20px;color:var(--t3);"><i class="fa fa-spinner fa-spin"></i> Loading...</div>
                <div id="giError" style="display:none;padding:12px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:8px;color:#ef4444;font-size:12.5px;margin-top:12px;">
                    <i class="fa fa-exclamation-triangle"></i> <span id="giErrMsg"></span>
                </div>
            </div>
            <div style="padding:14px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;">
                <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-info btn-sm" id="giSubmit" disabled><i class="fa fa-file-text-o"></i> Generate</button>
            </div>
        </div>
    </div>
</div>

<!-- ═══════════ NEW INVOICE MODAL ═══════════ -->
<div class="modal fade" id="newInvModal" tabindex="-1" data-backdrop="static">
    <div class="modal-dialog" style="max-width:640px;">
        <div class="modal-content" style="border-radius:14px;overflow:hidden;border:1px solid var(--border);background:var(--bg2);">
            <div style="padding:18px 24px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;">
                <div style="width:40px;height:40px;border-radius:10px;background:rgba(139,92,246,.12);display:flex;align-items:center;justify-content:center;">
                    <i class="fa fa-plus-circle" style="font-size:18px;color:#8b5cf6;"></i>
                </div>
                <div style="flex:1;">
                    <h4 id="newInvTitle" style="margin:0;font-size:16px;font-weight:700;color:var(--t1);">New Invoice</h4>
                    <div style="font-size:11.5px;color:var(--t3);font-family:var(--font-m);">Create an invoice with optional immediate payment</div>
                </div>
                <button type="button" class="close" data-dismiss="modal" style="font-size:22px;color:var(--t3);opacity:.7;">&times;</button>
            </div>
            <form id="newInvForm">
                <input type="hidden" id="niPlanId" name="plan_id">
                <div class="modal-body" style="padding:20px 24px;">
                    <!-- School -->
                    <div class="form-group" style="margin-bottom:14px;">
                        <label style="font-size:12px;font-weight:600;color:var(--t2);">School <span style="color:#ef4444;">*</span></label>
                        <select class="form-control" name="school_uid" id="niSchool" required style="height:40px;">
                            <option value="">-- Select School --</option>
                            <?php foreach($schools as $uid => $s): ?>
                            <option value="<?= htmlspecialchars($uid) ?>"><?= htmlspecialchars($s['name']) ?> <?= !empty($s['school_code']) ? '('.$s['school_code'].')' : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <!-- Plan info -->
                    <div id="niPlanCard" style="display:none;margin-bottom:14px;padding:12px 16px;background:var(--bg3);border:1px solid var(--border);border-radius:10px;">
                        <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
                            <i class="fa fa-tags" style="color:var(--sa3);"></i>
                            <span style="font-size:13px;font-weight:700;color:var(--t1);" id="niPlanName">—</span>
                            <span id="niPlanCycle" style="font-size:10px;padding:2px 8px;border-radius:10px;background:rgba(139,92,246,.12);color:#8b5cf6;font-family:var(--font-m);margin-left:auto;">—</span>
                        </div>
                        <div style="display:flex;gap:14px;font-size:12px;color:var(--t3);font-family:var(--font-m);">
                            <span>Price: <strong style="color:var(--t1);" id="niPlanPrice">—</strong></span>
                            <span>Status: <strong id="niPlanSt" style="color:var(--t1);">—</strong></span>
                        </div>
                        <div id="niDueInfo" style="display:none;margin-top:8px;padding:7px 10px;background:rgba(37,99,235,.06);border:1px solid rgba(37,99,235,.15);border-radius:6px;font-size:11.5px;color:var(--t2);">
                            <i class="fa fa-calendar" style="color:#2563eb;margin-right:4px;"></i>
                            Next period: <strong id="niPeriodLabel">—</strong> &middot; Due: <strong id="niDueLabel" style="color:#2563eb;">—</strong>
                        </div>
                    </div>
                    <div id="niPlanLoad" style="display:none;margin-bottom:14px;padding:16px;text-align:center;color:var(--t3);"><i class="fa fa-spinner fa-spin"></i> Loading...</div>
                    <div id="niPlanErr" style="display:none;margin-bottom:14px;padding:12px 16px;background:rgba(239,68,68,.08);border:1px solid rgba(239,68,68,.2);border-radius:8px;color:#ef4444;font-size:12.5px;">
                        <i class="fa fa-exclamation-triangle"></i> <span id="niPlanErrMsg"></span>
                    </div>
                    <!-- Amounts -->
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group" style="margin-bottom:14px;">
                                <label style="font-size:12px;font-weight:600;color:var(--t2);">Invoice Amount (₹) <span style="color:#ef4444;">*</span></label>
                                <input type="number" class="form-control" name="amount" id="niAmount" min="1" step="0.01" required style="height:40px;">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group" style="margin-bottom:14px;">
                                <label style="font-size:12px;font-weight:600;color:var(--t2);">Status</label>
                                <select class="form-control" name="status" id="niStatus" style="height:40px;">
                                    <option value="pending">Pending (unpaid)</option>
                                    <option value="paid">Paid in Full</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group" style="margin-bottom:14px;">
                                <label style="font-size:12px;font-weight:600;color:var(--t2);">Invoice Date</label>
                                <input type="date" class="form-control" name="invoice_date" value="<?= date('Y-m-d') ?>" style="height:40px;">
                            </div>
                        </div>
                    </div>
                    <!-- Dates -->
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group" style="margin-bottom:14px;">
                                <label style="font-size:12px;font-weight:600;color:var(--t2);">Due Date <span style="color:#ef4444;">*</span></label>
                                <input type="date" class="form-control" name="due_date" id="niDueDate" required style="height:40px;">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group" style="margin-bottom:14px;">
                                <label style="font-size:12px;font-weight:600;color:var(--t2);">Period Start</label>
                                <input type="date" class="form-control" name="period_start" id="niPeriodStart" style="height:40px;">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group" style="margin-bottom:14px;">
                                <label style="font-size:12px;font-weight:600;color:var(--t2);">Period End</label>
                                <input type="date" class="form-control" name="period_end" id="niPeriodEnd" style="height:40px;">
                            </div>
                        </div>
                    </div>
                    <!-- Paid-only fields -->
                    <div id="niPaidRow" style="display:none;" class="row">
                        <div class="col-md-4">
                            <div class="form-group" style="margin-bottom:14px;">
                                <label style="font-size:12px;font-weight:600;color:var(--t2);">Paid Date</label>
                                <input type="date" class="form-control" name="paid_date" value="<?= date('Y-m-d') ?>" style="height:40px;">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group" style="margin-bottom:14px;">
                                <label style="font-size:12px;font-weight:600;color:var(--t2);">Payment Mode</label>
                                <select class="form-control" name="pay_mode" style="height:40px;">
                                    <option value="Cash">Cash</option>
                                    <option value="Bank Transfer" selected>Bank Transfer</option>
                                    <option value="UPI">UPI</option>
                                    <option value="Cheque">Cheque</option>
                                    <option value="Online">Online</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group" style="margin-bottom:14px;">
                                <label style="font-size:12px;font-weight:600;color:var(--t2);">Notes</label>
                                <input type="text" class="form-control" name="notes" placeholder="Optional..." style="height:40px;">
                            </div>
                        </div>
                    </div>
                </div>
                <div style="padding:14px 24px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:8px;">
                    <button type="button" class="btn btn-default btn-sm" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success btn-sm" id="niSubmit" disabled><i class="fa fa-save"></i> Create Invoice</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
var payData = [], _plan = null, _colBal = 0;
var B = BASE_URL + 'superadmin/plans/';

var ST = {
    paid:    {bg:'rgba(34,197,94,.1)',  c:'#16a34a', lbl:'Paid'},
    partial: {bg:'rgba(249,115,22,.1)', c:'#ea580c', lbl:'Partial'},
    pending: {bg:'rgba(37,99,235,.1)',  c:'#2563eb', lbl:'Pending'},
    overdue: {bg:'rgba(239,68,68,.1)',  c:'#dc2626', lbl:'Overdue'},
    failed:  {bg:'rgba(107,114,128,.1)',c:'#6b7280', lbl:'Failed'}
};

function esc(s){ return $('<span>').text(s||'').html(); }
function R(n){ return '\u20B9'+Number(n||0).toLocaleString('en-IN',{minimumFractionDigits:0,maximumFractionDigits:2}); }
function D(d){ if(!d) return '—'; try{return new Date(d+'T00:00:00').toLocaleDateString('en-IN',{day:'2-digit',month:'short',year:'numeric'});}catch(e){return d;} }
function cap(s){ return s ? s.charAt(0).toUpperCase()+s.slice(1) : ''; }

/* ══════ LOAD ══════ */
function load(){
    $.post(B+'fetch_payments',{},function(r){
        if(r.status!=='success'){saToast('Load failed','error');return;}
        payData=r.rows||[];
        kpi();
        tbl();
    },'json');
}

/* ══════ KPI ══════ */
function kpi(){
    var c={paid:0,partial:0,pending:0,overdue:0,failed:0},rev=0,bal=0,ov=0;
    payData.forEach(function(p){
        var s=p.status||'pending';
        if(c[s]!==undefined) c[s]++;
        rev+=parseFloat(p.amount_paid||0);
        bal+=parseFloat(p.balance||0);
        if(s==='overdue') ov+=parseFloat(p.balance||0);
    });
    $('#kpi_total').text(payData.length);
    $('#kpi_revenue').text(R(rev));
    $('#kpi_balance').text(R(bal));
    $('#kpi_overdue').text(R(ov));
    $('#kpi_partial').text(c.partial);
    $('#kpi_paid').text(c.paid);
    $('#kpi_pending').text(c.pending);
}

/* ══════ TABLE ══════ */
function tbl(){
    var f=$('.pay-filter.active').data('f')||'all';
    var q=($('#paySearch').val()||'').toLowerCase();
    var so=$('#paySortBy').val()||'newest';
    var today=new Date().toISOString().slice(0,10);

    var rows=payData.filter(function(p){
        if(f!=='all' && p.status!==f) return false;
        if(q){
            var h=((p.school_name||'')+(p.school_uid||'')+(p.plan_name||'')+(p.payment_id||'')).toLowerCase();
            if(h.indexOf(q)<0) return false;
        }
        return true;
    });

    rows.sort(function(a,b){
        if(so==='due_asc')      return (a.due_date||'9').localeCompare(b.due_date||'9');
        if(so==='balance_desc') return (parseFloat(b.balance)||0)-(parseFloat(a.balance)||0);
        if(so==='amount_desc')  return (parseFloat(b.amount)||0)-(parseFloat(a.amount)||0);
        return (b.created_at||'').localeCompare(a.created_at||'');
    });

    if(!rows.length){
        $('#payTableBody').html('<tr><td colspan="10" style="text-align:center;padding:32px;color:var(--t3);">No invoices match.</td></tr>');
        return;
    }

    var html=rows.map(function(p){
        var st=ST[p.status]||ST.pending;
        var dd=p.days_due, amt=parseFloat(p.amount||0), paid=parseFloat(p.amount_paid||0), bal=parseFloat(p.balance||0);
        // Paid % bar
        var pct=amt>0?Math.min(100,Math.round(paid/amt*100)):0;
        var barColor=pct>=100?'#22c55e':(pct>0?'#f97316':'var(--border)');

        // Days indicator
        var dayH='—';
        if(p.status==='paid'){
            dayH='<span style="color:#22c55e;font-size:11px;"><i class="fa fa-check"></i></span>';
        } else if(dd!==null&&dd!==undefined){
            if(dd<0) dayH='<span style="color:#ef4444;font-weight:700;font-size:11px;">'+Math.abs(dd)+'d late</span>';
            else if(dd===0) dayH='<span style="color:#f97316;font-weight:700;font-size:11px;">Today</span>';
            else if(dd<=7)  dayH='<span style="color:#f97316;font-size:11px;">'+dd+'d</span>';
            else dayH='<span style="color:#22c55e;font-size:11px;">'+dd+'d</span>';
        }

        // Cycle badge
        var cyc=p.billing_cycle||'annual';
        var cycC=cyc==='monthly'?'#2563eb':(cyc==='quarterly'?'#8b5cf6':'#0f766e');

        // Period
        var per='—';
        if(p.period_start&&p.period_end) per=D(p.period_start)+' – '+D(p.period_end);

        return '<tr class="inv-row" data-uid="'+esc(p.school_uid)+'" style="cursor:pointer;">'
            +'<td style="padding:10px 14px;"><strong style="color:var(--t1);font-size:12.5px;">'+esc(p.school_name)+'</strong>'
            +'<div style="font-size:10px;color:var(--t3);font-family:var(--font-m);">'+esc(p.payment_id)+'</div></td>'
            +'<td style="padding:10px 14px;font-size:12px;">'+esc(p.plan_name||'—')
            +'<div><span style="font-size:10px;padding:1px 6px;border-radius:6px;background:rgba('+cycC.replace('#','')+');color:'+cycC+';background:'+cycC+'18;font-weight:600;">'+cap(cyc)+'</span></div></td>'
            +'<td style="padding:10px 14px;font-weight:700;color:var(--t1);font-size:13px;">'+R(amt)+'</td>'
            +'<td style="padding:10px 14px;">'
            +'<div style="font-weight:600;color:#22c55e;font-size:12.5px;">'+R(paid)+'</div>'
            +'<div style="width:60px;height:4px;background:var(--border);border-radius:2px;margin-top:3px;">'
            +'<div style="width:'+pct+'%;height:100%;background:'+barColor+';border-radius:2px;"></div></div>'
            +'<div style="font-size:9px;color:var(--t3);">'+pct+'%</div>'
            +'</td>'
            +'<td style="padding:10px 14px;font-weight:700;color:'+(bal>0?'#ef4444':'#22c55e')+';font-size:13px;">'+R(bal)+'</td>'
            +'<td style="padding:10px 14px;"><span style="font-size:11px;padding:3px 10px;border-radius:8px;background:'+st.bg+';color:'+st.c+';font-weight:600;">'+st.lbl+'</span></td>'
            +'<td style="padding:10px 14px;font-size:12px;">'+D(p.due_date)+'</td>'
            +'<td style="padding:10px 14px;">'+dayH+'</td>'
            +'<td style="padding:10px 14px;font-size:11px;">'+per+'</td>'
            +'<td style="padding:10px 14px;white-space:nowrap;" onclick="event.stopPropagation()">'
            +(bal>0?'<button class="btn btn-success btn-xs col-btn" data-pid="'+esc(p.payment_id)+'" title="Collect Payment"><i class="fa fa-inr"></i></button> ':'')
            +'<button class="btn btn-default btn-xs upd-btn" data-pid="'+esc(p.payment_id)+'" title="Edit"><i class="fa fa-edit"></i></button> '
            +'<button class="btn btn-danger btn-xs del-btn" data-pid="'+esc(p.payment_id)+'" title="Delete"><i class="fa fa-trash"></i></button>'
            +'</td></tr>';
    }).join('');
    $('#payTableBody').html(html);
}

/* ══════ FILTERS ══════ */
$(document).on('click','.pay-filter',function(){$('.pay-filter').removeClass('active');$(this).addClass('active');tbl();});
$('#paySearch').on('input',tbl);
$('#paySortBy').on('change',tbl);

/* ══════ COLLECT PAYMENT MODAL ══════ */
$(document).on('click','.col-btn',function(){
    var pid=$(this).data('pid');
    var p=payData.find(function(x){return x.payment_id===pid;});
    if(!p) return;

    var amt=parseFloat(p.amount||0), paid=parseFloat(p.amount_paid||0), bal=parseFloat(p.balance||(amt-paid));
    _colBal=bal;

    $('#colInvId').val(pid);
    $('#colInvLabel').text(esc(p.school_name)+' — '+pid);
    $('#colInvAmt').text(R(amt));
    $('#colPaidSoFar').text(R(paid));
    $('#colBalance').text(R(bal));
    $('#colPayAmt').val('').attr('max',bal).attr('placeholder','Max '+R(bal));
    $('#colPayHint').text('Balance due: '+R(bal));
    $('#colPayDate').val(new Date().toISOString().slice(0,10));
    $('#colPayNote').val('');
    $('#colSubmitBtn').prop('disabled',true);

    // Show previous transactions
    var txns=p.transactions;
    if(txns && typeof txns==='object' && Object.keys(txns).length){
        var th='';
        Object.keys(txns).forEach(function(tid){
            var t=txns[tid];
            th+='<div style="display:flex;justify-content:space-between;padding:5px 8px;background:var(--bg3);border-radius:6px;margin-bottom:4px;font-size:11.5px;">'
                +'<span style="color:var(--t2);">'+D(t.date)+' &middot; '+esc(t.mode||'—')+'</span>'
                +'<span style="color:#22c55e;font-weight:600;">'+R(t.amount)+'</span></div>';
        });
        $('#colTxnItems').html(th);
        $('#colTxnList').show();
    } else {
        $('#colTxnList').hide();
    }

    $('#collectModal').modal('show');
});

$('#colPayAmt').on('input',function(){
    var v=parseFloat($(this).val()||0);
    $('#colSubmitBtn').prop('disabled',v<=0);
    if(v>_colBal){
        $('#colPayHint').html('<span style="color:#f97316;">Amount exceeds balance — will be capped at '+R(_colBal)+'</span>');
    } else if(v>0 && v<_colBal){
        $('#colPayHint').text('Partial payment — '+R(_colBal-v)+' will remain');
    } else if(v===_colBal){
        $('#colPayHint').html('<span style="color:#22c55e;">Full balance — invoice will be marked Paid</span>');
    } else {
        $('#colPayHint').text('Balance due: '+R(_colBal));
    }
});

$('.col-quick-fill').on('click',function(){
    var pct=parseInt($(this).data('pct'));
    var v=Math.round(_colBal*(pct/100)*100)/100;
    $('#colPayAmt').val(v).trigger('input');
});

$('#colSubmitBtn').on('click',function(){
    var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
    $.post(B+'collect_payment',{
        invoice_id:$('#colInvId').val(),
        pay_amount:$('#colPayAmt').val(),
        pay_date:$('#colPayDate').val(),
        pay_mode:$('#colPayMode').val(),
        pay_note:$('#colPayNote').val()
    },function(r){
        $b.prop('disabled',false).html('<i class="fa fa-check"></i> Record Payment');
        saToast(r.message,r.status);
        if(r.status==='success'){$('#collectModal').modal('hide');load();}
    },'json').fail(function(){
        $b.prop('disabled',false).html('<i class="fa fa-check"></i> Record Payment');
        saToast('Network error','error');
    });
});

/* ══════ SCHOOL LEDGER DRAWER ══════ */
$(document).on('click','.inv-row',function(){
    var uid=$(this).data('uid');
    if(!uid) return;
    var nm=''; payData.forEach(function(p){if(p.school_uid===uid&&p.school_name)nm=p.school_name;});
    $('#drSchoolName').text(nm||uid);
    $('#drPlanInfo,#drBilled,#drPaid,#drBalance,#drExpiry,#drSubSt').text('—');
    $('#drTimeline').html('<div style="text-align:center;padding:20px;color:var(--t3);"><i class="fa fa-spinner fa-spin"></i></div>');
    $('#ledgerDrawer,#drOverlay').show();

    $.post(B+'fetch_school_payments',{school_uid:uid},function(r){
        if(r.status!=='success') return;
        $('#drPlanInfo').text((r.plan_name||'—')+' · '+cap(r.billing_cycle||''));
        $('#drBilled').text(R(r.total_billed));
        $('#drPaid').text(R(r.total_paid));
        var bal=r.total_balance||0;
        $('#drBalance').text(R(bal)).css('color',bal>0?'#ef4444':'#22c55e');
        $('#drExpiry').text(D(r.expiry_date));
        var s=r.sub_status||'Inactive',sc=s.toLowerCase()==='active'?'#22c55e':(s.toLowerCase()==='suspended'?'#ef4444':'#f97316');
        $('#drSubSt').html('<span style="color:'+sc+';">'+esc(s)+'</span>');

        var rows=r.rows||[];
        if(!rows.length){$('#drTimeline').html('<div style="text-align:center;padding:20px;color:var(--t3);">No invoices found.</div>');return;}

        var h=rows.map(function(p){
            var st=ST[p.status]||ST.pending;
            var amt=parseFloat(p.amount||0),pd=parseFloat(p.amount_paid||0),bl=parseFloat(p.balance||0);
            var pct=amt>0?Math.round(pd/amt*100):0;
            var txns=p.transactions||{};
            var txH='';
            Object.keys(txns).forEach(function(tid){
                var t=txns[tid];
                txH+='<div style="display:flex;justify-content:space-between;padding:3px 0;font-size:10.5px;color:var(--t3);border-bottom:1px dashed var(--border);">'
                    +'<span>'+D(t.date)+' &middot; '+esc(t.mode||'')+(t.note?' &middot; '+esc(t.note):'')+'</span>'
                    +'<span style="color:#22c55e;font-weight:600;">'+R(t.amount)+'</span></div>';
            });

            return '<div style="padding:14px 0;border-bottom:1px solid var(--border);">'
                +'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">'
                +'<strong style="color:var(--t1);font-size:13px;">'+R(amt)+'</strong>'
                +'<span style="font-size:10.5px;padding:2px 8px;border-radius:8px;background:'+st.bg+';color:'+st.c+';font-weight:600;">'+st.lbl+'</span>'
                +'</div>'
                +'<div style="display:flex;gap:12px;font-size:11px;color:var(--t3);margin-bottom:6px;">'
                +'<span>Paid: <strong style="color:#22c55e;">'+R(pd)+'</strong> ('+pct+'%)</span>'
                +'<span>Balance: <strong style="color:'+(bl>0?'#ef4444':'#22c55e')+';">'+R(bl)+'</strong></span>'
                +'</div>'
                +'<div style="width:100%;height:4px;background:var(--border);border-radius:2px;margin-bottom:6px;">'
                +'<div style="width:'+pct+'%;height:100%;background:'+(pct>=100?'#22c55e':'#f97316')+';border-radius:2px;"></div></div>'
                +'<div style="font-size:10.5px;color:var(--t3);">'
                +'Due: '+D(p.due_date)
                +(p.period_start?' &middot; Period: '+D(p.period_start)+' – '+D(p.period_end):'')
                +'</div>'
                +(txH?'<div style="margin-top:8px;padding:6px 8px;background:var(--bg3);border-radius:6px;"><div style="font-size:9px;color:var(--t3);text-transform:uppercase;margin-bottom:4px;">Transactions</div>'+txH+'</div>':'')
                +'<div style="font-size:9.5px;color:var(--t3);margin-top:4px;opacity:.6;">'+esc(p.payment_id)+'</div>'
                +'</div>';
        }).join('');
        $('#drTimeline').html(h);
    },'json');
});
$('#closeDrBtn,#drOverlay').on('click',function(){$('#ledgerDrawer,#drOverlay').hide();});

/* ══════ GENERATE INVOICE ══════ */
$('#genInvoiceBtn').on('click',function(){
    $('#giSchool').val('');$('#giPreview,#giLoading,#giError,#giOutstanding').hide();$('#giSubmit').prop('disabled',true);
    $('#genInvModal').modal('show');
});

$('#giSchool').on('change',function(){
    var uid=$(this).val();
    $('#giPreview,#giError,#giOutstanding').hide();$('#giSubmit').prop('disabled',true);
    if(!uid) return;
    $('#giLoading').show();
    $.post(B+'get_school_plan',{school_uid:uid},function(r){
        $('#giLoading').hide();
        if(r.status==='success'&&r.plan_id){
            $('#giPlan').text(r.plan_name);
            $('#giCycle').text(cap(r.billing_cycle));
            $('#giAmt').text(R(r.price));
            $('#giDue').text(D(r.next_due_date));
            $('#giPeriod').text(D(r.next_period_start)+' – '+D(r.next_period_end));
            $('#giPreview').show();
            if(r.outstanding_balance>0){
                $('#giOutMsg').text('This school has ₹'+Number(r.outstanding_balance).toLocaleString('en-IN')+' outstanding on invoice '+r.outstanding_id+'. Collect that first.');
                $('#giOutstanding').show();
                $('#giSubmit').prop('disabled',true);
            } else {
                $('#giSubmit').prop('disabled',false);
            }
        } else {
            $('#giErrMsg').text(r.message||'No plan assigned.');$('#giError').show();
        }
    },'json').fail(function(){$('#giLoading').hide();$('#giErrMsg').text('Network error');$('#giError').show();});
});

$('#giSubmit').on('click',function(){
    var $b=$(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i>');
    $.post(B+'generate_invoice',{school_uid:$('#giSchool').val()},function(r){
        $b.prop('disabled',false).html('<i class="fa fa-file-text-o"></i> Generate');
        if(r.status==='success'){saToast(r.message,'success');$('#genInvModal').modal('hide');load();}
        else{$('#giErrMsg').text(r.message||'Failed');$('#giError').show();}
    },'json').fail(function(){$b.prop('disabled',false).html('<i class="fa fa-file-text-o"></i> Generate');saToast('Network error','error');});
});

/* ══════ NEW INVOICE MODAL ══════ */
$('#addPaymentBtn').on('click',function(){
    $('#newInvForm')[0].reset();_plan=null;
    $('#niPlanId').val('');$('#niPlanCard,#niPlanLoad,#niPlanErr,#niDueInfo,#niPaidRow').hide();
    $('[name=invoice_date]').val(new Date().toISOString().slice(0,10));
    $('#niSubmit').prop('disabled',true);
    $('#newInvModal').modal('show');
});

$('#niStatus').on('change',function(){
    var isPaid=$(this).val()==='paid';
    $('#niPaidRow').toggle(isPaid);
    if(isPaid){var invDate=$('[name=invoice_date]').val();$('[name=paid_date]').val(invDate||new Date().toISOString().slice(0,10)).attr('min',invDate||'');}
});

$('#niSchool').on('change',function(){
    var uid=$(this).val();
    _plan=null;$('#niPlanId').val('');$('#niPlanCard,#niPlanErr,#niDueInfo').hide();$('#niAmount,#niDueDate,#niPeriodStart,#niPeriodEnd').val('');niCheck();
    if(!uid) return;
    $('#niPlanLoad').show();
    $.post(B+'get_school_plan',{school_uid:uid},function(r){
        $('#niPlanLoad').hide();
        if(r.status==='success'&&r.plan_id){
            _plan=r;$('#niPlanId').val(r.plan_id);
            $('#niPlanName').text(r.plan_name);$('#niPlanCycle').text(cap(r.billing_cycle));
            $('#niPlanPrice').text(R(r.price));$('#niPlanSt').text(r.sub_status||'—');
            $('#niPlanCard').show();
            if(r.price>0&&!$('#niAmount').val()) $('#niAmount').val(r.price);
            if(r.next_due_date){
                $('#niDueDate').val(r.next_due_date);
                $('#niPeriodStart').val(r.next_period_start||'');
                $('#niPeriodEnd').val(r.next_period_end||'');
                $('#niPeriodLabel').text(D(r.next_period_start)+' – '+D(r.next_period_end));
                $('#niDueLabel').text(D(r.next_due_date));
                $('#niDueInfo').show();
            }
            niCheck();
        } else {
            $('#niPlanErrMsg').text(r.message||'No plan assigned.');$('#niPlanErr').show();
        }
    },'json').fail(function(){$('#niPlanLoad').hide();$('#niPlanErrMsg').text('Network error');$('#niPlanErr').show();});
});

function niCheck(){
    var ok=$('#niSchool').val()&&$('#niPlanId').val()&&parseFloat($('#niAmount').val())>0&&$('#niDueDate').val();
    $('#niSubmit').prop('disabled',!ok);
}
$('#niSchool,#niAmount,#niDueDate').on('change input',niCheck);
$('[name=invoice_date]').on('change',function(){var v=$(this).val();$('[name=paid_date]').attr('min',v);if($('[name=paid_date]').val()<v)$('[name=paid_date]').val(v);});

$('#newInvForm').on('submit',function(e){
    e.preventDefault();
    var invDate=$('[name=invoice_date]').val(), paidDate=$('[name=paid_date]').val(), status=$('#niStatus').val();
    if(status==='paid'&&paidDate&&invDate&&paidDate<invDate){saToast('Paid date cannot be before invoice date.','error');return;}
    if(paidDate&&$('#niDueDate').val()&&paidDate>$('#niDueDate').val()){saToast('Paid date cannot be after due date.','error');return;}
    var $b=$('#niSubmit').prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i>');
    $.post(B+'add_payment',$(this).serialize(),function(r){
        $b.prop('disabled',false).html('<i class="fa fa-save"></i> Create Invoice');
        if(r.status==='success'){saToast(r.message,'success');$('#newInvModal').modal('hide');load();}
        else saToast(r.message||'Failed','error');
    },'json').fail(function(){$b.prop('disabled',false).html('<i class="fa fa-save"></i> Create Invoice');saToast('Network error','error');});
});

/* ══════ EDIT (update_payment) ══════ */
$(document).on('click','.upd-btn',function(){
    var pid=$(this).data('pid');
    var p=payData.find(function(x){return x.payment_id===pid;});
    if(!p) return;
    var newSt=prompt('Update status for '+pid+'\nCurrent: '+p.status+'\nOptions: pending, partial, paid, overdue, failed',p.status);
    if(!newSt||newSt===p.status) return;
    var data={payment_id:pid,status:newSt};
    if(newSt==='paid') data.paid_date=new Date().toISOString().slice(0,10);
    $.post(B+'update_payment',data,function(r){
        saToast(r.message,r.status);if(r.status==='success') load();
    },'json');
});

/* ══════ DELETE ══════ */
$(document).on('click','.del-btn',function(){
    var pid=$(this).data('pid');
    if(!confirm('Delete invoice '+pid+'? This cannot be undone.')) return;
    $.post(B+'delete_payment',{payment_id:pid},function(r){
        saToast(r.message,r.status);if(r.status==='success') load();
    },'json');
});

$(function(){load();});
</script>
