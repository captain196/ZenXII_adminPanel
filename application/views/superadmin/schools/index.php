<?php $schools = $schools ?? []; ?>

<section class="content-header">
    <h1><i class="fa fa-building" style="color:var(--sa3);margin-right:10px;font-size:20px;"></i>Manage Schools</h1>
    <ol class="breadcrumb">
        <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
        <li class="active">Schools</li>
    </ol>
</section>

<section class="content" style="padding:20px 24px;">

    <!-- Toolbar -->
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button class="btn btn-default btn-sm sa-filter-btn active" data-filter="all">All (<?= count($schools) ?>)</button>
            <button class="btn btn-default btn-sm sa-filter-btn" data-filter="active">Active</button>
            <button class="btn btn-default btn-sm sa-filter-btn" data-filter="inactive">Inactive</button>
            <button class="btn btn-default btn-sm sa-filter-btn" data-filter="suspended">Suspended</button>
        </div>
        <a href="<?= base_url('superadmin/schools/create') ?>" class="btn btn-primary btn-sm">
            <i class="fa fa-plus"></i> Onboard New School
        </a>
    </div>

    <div class="box box-primary">
        <div class="box-header">
            <i class="fa fa-list" style="color:var(--sa3);margin-right:8px;"></i>
            <span class="box-title">All Schools</span>
        </div>
        <div class="box-body" style="padding:0 !important;">
            <div style="overflow-x:auto;">
            <table class="table sa-table" id="schoolsTable" style="margin:0;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>School Name</th>
                        <th>City</th>
                        <th>Subdomain</th>
                        <th>Plan</th>
                        <th>Expiry</th>
                        <th>Students</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach($schools as $i => $s): ?>
                <tr data-status="<?= htmlspecialchars($s['status']) ?>">
                    <td style="color:var(--t3);font-family:var(--font-m);font-size:11px;"><?= $i + 1 ?></td>
                    <td>
                        <div style="font-weight:600;color:var(--t1);"><?= htmlspecialchars($s['name']) ?></div>
                        <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);"><?= htmlspecialchars($s['uid']) ?></div>
                    </td>
                    <td style="color:var(--t2);"><?= htmlspecialchars($s['city']) ?></td>
                    <td><span style="font-family:var(--font-m);font-size:12px;color:var(--sa3);"><?= htmlspecialchars($s['domain_id'] ?? '') ?></span></td>
                    <td><span class="label label-warning"><?= htmlspecialchars($s['plan_name']) ?></span></td>
                    <td>
                        <?php
                        $exp   = $s['expiry_date'] ?? '';
                        $days  = $exp ? (int)ceil((strtotime($exp) - time()) / 86400) : null;
                        $cls   = ($days !== null && $days < 0) ? 'label-danger' : (($days !== null && $days <= 15) ? 'label-warning' : 'label-success');
                        ?>
                        <span class="label <?= $cls ?>"><?= $exp ?: 'N/A' ?></span>
                        <?php if($days !== null && $days >= 0 && $days <= 15): ?>
                        <div style="font-size:10px;color:var(--amber);margin-top:2px;"><?= $days ?> days left</div>
                        <?php endif; ?>
                    </td>
                    <td style="text-align:center;">
                        <span style="font-family:var(--font-d);font-size:15px;font-weight:700;color:var(--t1);"><?= number_format($s['students']) ?></span>
                    </td>
                    <td>
                        <?php
                        $sc = ['active'=>'label-success','inactive'=>'label-default','suspended'=>'label-danger'];
                        $st = $s['status'];
                        ?>
                        <span class="label <?= $sc[$st] ?? 'label-default' ?>"><?= ucfirst($st) ?></span>
                    </td>
                    <td>
                        <div style="display:flex;gap:5px;flex-wrap:nowrap;">
                            <a href="<?= base_url('superadmin/schools/view/' . $s['uid']) ?>"
                               class="btn btn-info btn-xs" title="View"><i class="fa fa-eye"></i></a>
                            <?php if($st === 'active'): ?>
                            <button class="btn btn-warning btn-xs sa-toggle-btn"
                                    data-uid="<?= $s['uid'] ?>" data-action="inactive" title="Deactivate">
                                <i class="fa fa-pause"></i>
                            </button>
                            <?php else: ?>
                            <button class="btn btn-success btn-xs sa-toggle-btn"
                                    data-uid="<?= $s['uid'] ?>" data-action="active" title="Activate">
                                <i class="fa fa-play"></i>
                            </button>
                            <?php endif; ?>
                            <button class="btn btn-default btn-xs sa-refresh-btn"
                                    data-uid="<?= $s['uid'] ?>" title="Refresh Stats">
                                <i class="fa fa-refresh"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div id="emptyStateRow" style="display:none;text-align:center;padding:32px;color:var(--t3);font-size:13px;">
                <i class="fa fa-search" style="font-size:24px;display:block;margin-bottom:8px;opacity:.4;"></i>
                No schools found in this category.
            </div>
        </div>
    </div>

</section>

<!-- Toggle Status Modal -->
<div class="modal fade" id="toggleModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title" id="toggleModalTitle">Change School Status</h4>
            </div>
            <div class="modal-body">
                <p id="toggleModalMsg" style="color:var(--t2);font-size:13.5px;"></p>
                <input type="hidden" id="toggleSchoolUid">
                <input type="hidden" id="toggleNewStatus">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmToggleBtn">Confirm</button>
            </div>
        </div>
    </div>
</div>

<script>
$(function(){

    // Filter buttons
    $('.sa-filter-btn').on('click', function(){
        $('.sa-filter-btn').removeClass('active');
        $(this).addClass('active');
        var filter = $(this).data('filter');
        var visible = 0;
        $('#schoolsTable tbody tr').each(function(){
            var st = $(this).data('status');
            var show = filter === 'all' || st === filter;
            $(this).toggle(show);
            if(show) visible++;
        });
        $('#emptyStateRow').toggle(visible === 0);
    });

    // Toggle status
    $('.sa-toggle-btn').on('click', function(){
        var uid    = $(this).data('uid');
        var action = $(this).data('action');
        var label  = action === 'active' ? 'activate' : 'deactivate';
        $('#toggleModalTitle').text('Confirm Status Change');
        $('#toggleModalMsg').text('Are you sure you want to ' + label + ' this school? This will immediately affect all admin users of that school.');
        $('#toggleSchoolUid').val(uid);
        $('#toggleNewStatus').val(action);
        $('#toggleModal').modal('show');
    });

    $('#confirmToggleBtn').on('click', function(){
        var uid    = $('#toggleSchoolUid').val();
        var status = $('#toggleNewStatus').val();
        var $btn   = $(this).prop('disabled', true).text('Updating...');

        $.ajax({
            url:  BASE_URL + 'superadmin/schools/toggle_status',
            type: 'POST',
            data: { school_uid: uid, status: status },
            success: function(r){
                if(r.status === 'success'){
                    saToast(r.message, 'success');
                    $('#toggleModal').modal('hide');
                    setTimeout(function(){ location.reload(); }, 1000);
                } else {
                    saToast(r.message, 'error');
                    $btn.prop('disabled', false).text('Confirm');
                }
            },
            error: function(){ saToast('Server error.', 'error'); $btn.prop('disabled', false).text('Confirm'); }
        });
    });

    // Refresh school stats
    $('.sa-refresh-btn').on('click', function(){
        var uid  = $(this).data('uid');
        var $btn = $(this).prop('disabled', true);
        $btn.find('i').addClass('fa-spin');

        $.ajax({
            url:  BASE_URL + 'superadmin/schools/refresh_school_stats',
            type: 'POST',
            data: { school_uid: uid },
            success: function(r){
                if(r.status === 'success'){
                    saToast('Stats refreshed: ' + r.total_students + ' students.', 'success');
                } else {
                    saToast(r.message, 'error');
                }
            },
            error: function(){ saToast('Server error.', 'error'); },
            complete: function(){ $btn.prop('disabled', false).find('i').removeClass('fa-spin'); }
        });
    });
});
</script>
