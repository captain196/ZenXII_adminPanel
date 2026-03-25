<?php
$plans             = $plans             ?? [];
$available_modules = $available_modules ?? [];
?>

<section class="content-header">
    <h1><i class="fa fa-tags" style="color:var(--sa3);margin-right:10px;font-size:20px;"></i>Subscription Plans</h1>
    <ol class="breadcrumb">
        <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
        <li class="active">Plans</li>
    </ol>
</section>

<section class="content" style="padding:20px 24px;">

    <!-- Quick-nav tabs -->
    <div style="display:flex;gap:8px;margin-bottom:20px;align-items:center;flex-wrap:wrap;">
        <a href="<?= base_url('superadmin/plans') ?>" class="btn btn-primary btn-sm">
            <i class="fa fa-tags"></i> Plan Catalogue
        </a>
        <a href="<?= base_url('superadmin/plans/subscriptions') ?>" class="btn btn-default btn-sm">
            <i class="fa fa-calendar-check-o"></i> Subscriptions
        </a>
        <a href="<?= base_url('superadmin/plans/payments') ?>" class="btn btn-default btn-sm">
            <i class="fa fa-money"></i> Payments
        </a>
        <div style="margin-left:auto;">
            <button class="btn btn-default btn-sm" id="seedDefaultsBtn">
                <i class="fa fa-magic"></i> Seed Default Plans
            </button>
        </div>
    </div>

    <!-- Plan Cards -->
    <div class="row" id="planCards" style="margin-bottom:24px;">
        <?php foreach($plans as $plan): ?>
        <div class="col-md-4" style="margin-bottom:20px;">
            <div class="box" style="border-top:3px solid var(--sa);">
                <div class="box-header">
                    <span class="box-title"><?= htmlspecialchars($plan['name'] ?? '') ?></span>
                    <span style="float:right;font-family:var(--font-d);font-size:18px;font-weight:800;color:var(--sa3);">
                        ₹<?= number_format($plan['price'] ?? 0) ?>
                        <span style="font-size:11px;color:var(--t3);font-weight:400;">/<?= $plan['billing_cycle'] ?? 'month' ?></span>
                    </span>
                </div>
                <div class="box-body">
                    <div style="display:flex;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
                        <span class="label label-info"><i class="fa fa-users"></i> <?= $plan['max_students'] ?? '∞' ?> students</span>
                        <span class="label label-info"><i class="fa fa-user-tie"></i> <?= $plan['max_staff'] ?? '∞' ?> staff</span>
                        <span class="label label-warning"><?= $plan['school_count'] ?? 0 ?> schools</span>
                    </div>
                    <!-- Modules -->
                    <div style="display:flex;flex-wrap:wrap;gap:5px;margin-bottom:14px;">
                        <?php foreach($available_modules as $mod_key => $mod_name):
                            $enabled = $plan['modules'][$mod_key] ?? false;
                        ?>
                        <span style="font-size:10.5px;padding:2px 7px;border-radius:5px;font-family:var(--font-m);
                            background:<?= $enabled ? 'rgba(124,58,237,.12)' : 'rgba(255,255,255,.04)' ?>;
                            color:<?= $enabled ? 'var(--sa3)' : 'var(--t4)' ?>;
                            border:1px solid <?= $enabled ? 'rgba(124,58,237,.22)' : 'rgba(255,255,255,.06)' ?>;">
                            <?php if($enabled): ?><i class="fa fa-check" style="font-size:9px;margin-right:3px;"></i><?php else: ?><i class="fa fa-times" style="font-size:9px;margin-right:3px;opacity:.4;"></i><?php endif; ?>
                            <?= htmlspecialchars($mod_name) ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;gap:8px;">
                        <button class="btn btn-default btn-xs sa-edit-plan-btn" data-pid="<?= htmlspecialchars($plan['plan_id']) ?>">
                            <i class="fa fa-edit"></i> Edit
                        </button>
                        <button class="btn btn-danger btn-xs sa-delete-plan-btn" data-pid="<?= htmlspecialchars($plan['plan_id']) ?>" data-name="<?= htmlspecialchars($plan['name'] ?? '') ?>">
                            <i class="fa fa-trash"></i> Delete
                        </button>
                    </div>
                </div>
                <div class="box-footer" style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);">
                    ID: <?= htmlspecialchars($plan['plan_id']) ?> · Grace: <?= $plan['grace_days'] ?? 7 ?> days
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <!-- New Plan Card -->
        <div class="col-md-4" style="margin-bottom:20px;">
            <div style="border:2px dashed var(--brd2);border-radius:var(--r);height:100%;min-height:200px;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all var(--ease);"
                 id="addPlanCard"
                 onmouseover="this.style.borderColor='var(--sa3)';this.style.background='var(--sa-dim)'"
                 onmouseout="this.style.borderColor='var(--brd2)';this.style.background='transparent'">
                <div style="text-align:center;color:var(--t3);">
                    <i class="fa fa-plus-circle" style="font-size:28px;margin-bottom:8px;display:block;color:var(--sa3);opacity:.6;"></i>
                    <div style="font-size:13px;font-weight:600;">Create New Plan</div>
                </div>
            </div>
        </div>
    </div>

</section>

<!-- Create Plan Modal -->
<div class="modal fade" id="createPlanModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title" id="planModalTitle">Create Subscription Plan</h4>
            </div>
            <form id="planForm">
                <input type="hidden" id="planFormId" name="plan_id">
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Plan Name <span style="color:var(--rose);">*</span></label>
                                <input type="text" class="form-control" name="name" id="planName" placeholder="e.g. Standard" required>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Price (₹)</label>
                                <input type="number" class="form-control" name="price" id="planPrice" min="0" value="0">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Billing Cycle</label>
                                <select class="form-control" name="billing_cycle" id="planBilling">
                                    <option value="monthly">Monthly</option>
                                    <option value="quarterly">Quarterly</option>
                                    <option value="annual" selected>Annual</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Max Students</label>
                                <input type="number" class="form-control" name="max_students" id="planMaxStudents" value="500" min="1">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Max Staff</label>
                                <input type="number" class="form-control" name="max_staff" id="planMaxStaff" value="50" min="1">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="form-group">
                                <label>Grace Days</label>
                                <input type="number" class="form-control" name="grace_days" id="planGraceDays" value="7" min="0">
                            </div>
                        </div>
                    </div>
                    <hr style="border-color:var(--border);">
                    <label style="margin-bottom:12px;display:block;">Module Access</label>
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:8px;" id="moduleCheckboxes">
                        <?php foreach($available_modules as $mod_key => $mod_name): ?>
                        <label style="display:flex;align-items:center;gap:8px;padding:8px 12px;background:var(--bg3);border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:12.5px;font-weight:400 !important;text-transform:none !important;letter-spacing:0 !important;color:var(--t2) !important;">
                            <input type="checkbox" name="modules[]" value="<?= $mod_key ?>" class="mod-check" data-mod="<?= $mod_key ?>" style="width:14px;height:14px;accent-color:var(--sa);">
                            <?= htmlspecialchars($mod_name) ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div style="margin-top:8px;">
                        <button type="button" class="btn btn-default btn-xs" id="selectAllModules">Select All</button>
                        <button type="button" class="btn btn-default btn-xs" id="clearAllModules">Clear All</button>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary" id="savePlanBtn"><i class="fa fa-save"></i> Save Plan</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
$(function(){
    // Open create modal
    $('#addPlanCard').on('click', function(){ openPlanModal(); });

    // Seed defaults
    $('#seedDefaultsBtn').on('click', function(){
        var $btn = $(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Seeding...');
        $.post(BASE_URL+'superadmin/plans/seed_defaults', {}, function(r){
            saToast(r.message, r.status);
            if(r.status==='success' && r.seeded && r.seeded.length) setTimeout(function(){location.reload();},1000);
        },'json').always(function(){ $btn.prop('disabled',false).html('<i class="fa fa-magic"></i> Seed Default Plans'); });
    });

    function openPlanModal(planData){
        $('#planFormId').val('');
        $('#planForm')[0].reset();
        $('#planModalTitle').text(planData ? 'Edit Plan' : 'Create Subscription Plan');
        if(planData){
            $('#planFormId').val(planData.plan_id || '');
            $('#planName').val(planData.name || '');
            $('#planPrice').val(planData.price || 0);
            $('#planBilling').val(planData.billing_cycle || 'annual');
            $('#planMaxStudents').val(planData.max_students || 500);
            $('#planMaxStaff').val(planData.max_staff || 50);
            $('#planGraceDays').val(planData.grace_days || 7);
            $('.mod-check').each(function(){
                var mod = $(this).data('mod');
                $(this).prop('checked', !!(planData.modules && planData.modules[mod]));
            });
        }
        $('#createPlanModal').modal('show');
    }

    // Edit plan
    $(document).on('click', '.sa-edit-plan-btn', function(){
        var pid = $(this).data('pid');
        $.ajax({ url: BASE_URL+'superadmin/plans/fetch', type:'POST', data:{ plan_id:pid },
            success: function(r){
                if(r.status==='success') openPlanModal(Object.assign({plan_id: pid}, r.plan));
                else saToast(r.message, 'error');
            }
        });
    });

    // Delete plan
    $(document).on('click','.sa-delete-plan-btn', function(){
        var pid  = $(this).data('pid');
        var name = $(this).data('name');
        if(!confirm('Delete plan "'+name+'"? Schools on this plan must be reassigned first.')) return;
        $.ajax({ url: BASE_URL+'superadmin/plans/delete', type:'POST', data:{ plan_id:pid },
            success:function(r){ saToast(r.message, r.status); if(r.status==='success') setTimeout(function(){location.reload();},1000); },
            error:function(){ saToast('Server error.','error'); }
        });
    });

    // Module select all
    $('#selectAllModules').on('click', function(){ $('.mod-check').prop('checked',true); });
    $('#clearAllModules').on('click',  function(){ $('.mod-check').prop('checked',false); });

    // Form submit
    $('#planForm').on('submit', function(e){
        e.preventDefault();
        var pid  = $('#planFormId').val();
        var url  = pid ? BASE_URL+'superadmin/plans/update' : BASE_URL+'superadmin/plans/create';
        var $btn = $('#savePlanBtn').prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');

        $.ajax({ url:url, type:'POST', data:$(this).serialize(),
            success:function(r){
                saToast(r.message || (r.status==='success'?'Saved.':'Error.'), r.status);
                if(r.status==='success'){ $('#createPlanModal').modal('hide'); setTimeout(function(){location.reload();},800); }
                $btn.prop('disabled',false).html('<i class="fa fa-save"></i> Save Plan');
            },
            error:function(){ saToast('Server error.','error'); $btn.prop('disabled',false).html('<i class="fa fa-save"></i> Save Plan'); }
        });
    });
});
</script>
