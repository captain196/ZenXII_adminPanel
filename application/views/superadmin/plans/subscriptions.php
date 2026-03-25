
<section class="content-header">
    <h1><i class="fa fa-calendar-check-o" style="color:var(--sa3);margin-right:10px;font-size:20px;"></i>Subscription Tracking</h1>
    <ol class="breadcrumb">
        <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
        <li><a href="<?= base_url('superadmin/plans') ?>">Plans</a></li>
        <li class="active">Subscriptions</li>
    </ol>
</section>

<section class="content" style="padding:20px 24px;">

    <!-- Quick-nav -->
    <div style="display:flex;gap:8px;margin-bottom:20px;align-items:center;flex-wrap:wrap;">
        <a href="<?= base_url('superadmin/plans') ?>" class="btn btn-default btn-sm"><i class="fa fa-tags"></i> Plan Catalogue</a>
        <a href="<?= base_url('superadmin/plans/subscriptions') ?>" class="btn btn-primary btn-sm"><i class="fa fa-calendar-check-o"></i> Subscriptions</a>
        <a href="<?= base_url('superadmin/plans/payments') ?>" class="btn btn-default btn-sm"><i class="fa fa-money"></i> Payments</a>
        <div style="margin-left:auto;">
            <button class="btn btn-warning btn-sm" id="expireCheckBtn">
                <i class="fa fa-refresh"></i> Run Expiry Check
            </button>
        </div>
    </div>

    <!-- KPI cards -->
    <div class="row" id="kpiRow">
        <?php
        $kpis = [
            ['id'=>'kpi_active',       'label'=>'Active',        'icon'=>'fa-check-circle',    'color'=>'#22c55e'],
            ['id'=>'kpi_expiring',     'label'=>'Expiring Soon', 'icon'=>'fa-exclamation-circle','color'=>'#f97316'],
            ['id'=>'kpi_grace',        'label'=>'Grace Period',  'icon'=>'fa-clock-o',         'color'=>'#eab308'],
            ['id'=>'kpi_expired',      'label'=>'Expired',       'icon'=>'fa-times-circle',    'color'=>'#ef4444'],
            ['id'=>'kpi_suspended',    'label'=>'Suspended',     'icon'=>'fa-ban',             'color'=>'#6b7280'],
        ];
        foreach($kpis as $k): ?>
        <div class="col-xs-6 col-sm-4 col-md-2" style="margin-bottom:16px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:16px 12px;text-align:center;">
                <i class="fa <?= $k['icon'] ?>" style="font-size:22px;color:<?= $k['color'] ?>;margin-bottom:6px;display:block;"></i>
                <div id="<?= $k['id'] ?>" style="font-size:24px;font-weight:800;color:var(--t1);font-family:var(--font-d);">—</div>
                <div style="font-size:11px;color:var(--t3);font-family:var(--font-m);"><?= $k['label'] ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filter tabs -->
    <div style="display:flex;gap:6px;margin-bottom:16px;flex-wrap:wrap;">
        <button class="btn btn-default btn-sm sub-filter active" data-filter="all">All</button>
        <button class="btn btn-default btn-sm sub-filter" data-filter="active">Active</button>
        <button class="btn btn-default btn-sm sub-filter" data-filter="expiring_soon" style="border-color:#f97316;color:#f97316;">Expiring Soon</button>
        <button class="btn btn-default btn-sm sub-filter" data-filter="grace"          style="border-color:#eab308;color:#eab308;">Grace Period</button>
        <button class="btn btn-default btn-sm sub-filter" data-filter="expired"        style="border-color:#ef4444;color:#ef4444;">Expired</button>
        <button class="btn btn-default btn-sm sub-filter" data-filter="suspended"      style="border-color:#6b7280;color:#6b7280;">Suspended</button>
        <div style="margin-left:auto;">
            <input type="text" id="subSearch" class="form-control input-sm" placeholder="Search school..." style="width:200px;">
        </div>
    </div>

    <!-- Table -->
    <div class="box">
        <div class="box-body" style="padding:0;overflow-x:auto;">
            <table class="table table-hover" id="subTable" style="margin:0;min-width:800px;">
                <thead>
                    <tr style="background:var(--bg3);">
                        <th style="padding:12px 14px;font-size:11px;font-family:var(--font-m);color:var(--t3);border-bottom:1px solid var(--border);">SCHOOL</th>
                        <th style="padding:12px 14px;font-size:11px;font-family:var(--font-m);color:var(--t3);border-bottom:1px solid var(--border);">SCHOOL CODE</th>
                        <th style="padding:12px 14px;font-size:11px;font-family:var(--font-m);color:var(--t3);border-bottom:1px solid var(--border);">PLAN</th>
                        <th style="padding:12px 14px;font-size:11px;font-family:var(--font-m);color:var(--t3);border-bottom:1px solid var(--border);">EXPIRY DATE</th>
                        <th style="padding:12px 14px;font-size:11px;font-family:var(--font-m);color:var(--t3);border-bottom:1px solid var(--border);">GRACE END</th>
                        <th style="padding:12px 14px;font-size:11px;font-family:var(--font-m);color:var(--t3);border-bottom:1px solid var(--border);">DAYS LEFT</th>
                        <th style="padding:12px 14px;font-size:11px;font-family:var(--font-m);color:var(--t3);border-bottom:1px solid var(--border);">STATUS</th>
                        <th style="padding:12px 14px;font-size:11px;font-family:var(--font-m);color:var(--t3);border-bottom:1px solid var(--border);">LAST PAYMENT</th>
                        <th style="padding:12px 14px;font-size:11px;font-family:var(--font-m);color:var(--t3);border-bottom:1px solid var(--border);">ACTIONS</th>
                    </tr>
                </thead>
                <tbody id="subTableBody">
                    <tr><td colspan="9" style="text-align:center;padding:40px;color:var(--t3);">
                        <i class="fa fa-spinner fa-spin" style="font-size:20px;"></i><br>Loading...
                    </td></tr>
                </tbody>
            </table>
        </div>
    </div>

</section>

<!-- Expiry check result modal -->
<div class="modal fade" id="expireResultModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title"><i class="fa fa-refresh" style="color:var(--sa3);margin-right:8px;"></i>Expiry Check Result</h4>
            </div>
            <div class="modal-body" id="expireResultBody"></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-primary" data-dismiss="modal" onclick="loadSubs()">Refresh Table</button>
            </div>
        </div>
    </div>
</div>

<script>
var subData = [];

var statusCfg = {
    active:       { label:'Active',        cls:'label-success',  color:'#22c55e' },
    expiring_soon:{ label:'Expiring Soon',  cls:'label-warning',  color:'#f97316' },
    grace:        { label:'Grace Period',   cls:'label-warning',  color:'#eab308' },
    expired:      { label:'Expired',        cls:'label-danger',   color:'#ef4444' },
    suspended:    { label:'Suspended',      cls:'label-default',  color:'#6b7280' },
    inactive:     { label:'Inactive',       cls:'label-default',  color:'#9ca3af' },
};

function loadSubs(){
    $.post(BASE_URL+'superadmin/plans/fetch_subscriptions', {}, function(r){
        if(r.status !== 'success'){ saToast('Failed to load subscriptions.','error'); return; }
        subData = r.rows || [];
        renderKpi();
        renderTable($('.sub-filter.active').data('filter'), $('#subSearch').val());
    },'json');
}

function renderKpi(){
    var counts = {active:0, expiring_soon:0, grace:0, expired:0, suspended:0};
    subData.forEach(function(r){ if(counts[r.display] !== undefined) counts[r.display]++; });
    $('#kpi_active').text(counts.active);
    $('#kpi_expiring').text(counts.expiring_soon);
    $('#kpi_grace').text(counts.grace);
    $('#kpi_expired').text(counts.expired);
    $('#kpi_suspended').text(counts.suspended);
}

function renderTable(filter, search){
    filter = filter || 'all';
    search = (search || '').toLowerCase();

    var rows = subData.filter(function(r){
        if(filter !== 'all' && r.display !== filter) return false;
        if(search && r.name.toLowerCase().indexOf(search) < 0 && r.school_code.toLowerCase().indexOf(search) < 0) return false;
        return true;
    });

    if(!rows.length){
        $('#subTableBody').html('<tr><td colspan="9" style="text-align:center;padding:32px;color:var(--t3);">No records match the current filter.</td></tr>');
        return;
    }

    var html = rows.map(function(r){
        var cfg  = statusCfg[r.display] || statusCfg.inactive;
        var dl   = r.days_left;
        var dlTxt = (dl === null || dl === undefined) ? '—'
                  : (dl < 0   ? '<span style="color:#ef4444;">'+dl+' days</span>'
                  : (dl <= 30 ? '<span style="color:#f97316;font-weight:600;">'+dl+' days</span>'
                  :             dl+' days'));
        var graceLeft = r.grace_left !== null && r.grace_left !== undefined
            ? (r.grace_left < 0 ? '—' : r.grace_left + 'd') : '—';

        return '<tr>'
            +'<td style="padding:11px 14px;"><strong>'+escHtml(r.name)+'</strong><br>'
            +'<small style="color:var(--t3);">'+escHtml(r.uid)+'</small></td>'
            +'<td style="padding:11px 14px;"><code style="font-size:11px;">'+escHtml(r.school_code||'—')+'</code></td>'
            +'<td style="padding:11px 14px;">'+escHtml(r.plan_name)+'</td>'
            +'<td style="padding:11px 14px;">'+escHtml(r.expiry_date||'—')+'</td>'
            +'<td style="padding:11px 14px;">'+escHtml(r.grace_end||'—')+'</td>'
            +'<td style="padding:11px 14px;">'+dlTxt+'</td>'
            +'<td style="padding:11px 14px;"><span class="label '+cfg.cls+'">'+cfg.label+'</span></td>'
            +'<td style="padding:11px 14px;">'+escHtml(r.last_payment||'—')+'</td>'
            +'<td style="padding:11px 14px;">'
            +'<a href="'+BASE_URL+'superadmin/schools/view/'+encodeURIComponent(r.uid)+'" class="btn btn-default btn-xs"><i class="fa fa-eye"></i></a>'
            +'</td>'
            +'</tr>';
    }).join('');
    $('#subTableBody').html(html);
}

function escHtml(s){
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Filter buttons
$(document).on('click', '.sub-filter', function(){
    $('.sub-filter').removeClass('active');
    $(this).addClass('active');
    renderTable($(this).data('filter'), $('#subSearch').val());
});

$('#subSearch').on('input', function(){
    renderTable($('.sub-filter.active').data('filter'), this.value);
});

// Run expire check
$('#expireCheckBtn').on('click', function(){
    var $btn = $(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Running...');
    $.post(BASE_URL+'superadmin/plans/expire_check', {}, function(r){
        var html = '<p>'+escHtml(r.message||'')+'</p>';
        if(r.status==='success'){
            if(r.suspended && r.suspended.length){
                html += '<div style="margin-bottom:8px;"><strong>Suspended ('+r.suspended.length+'):</strong><br>'
                    + r.suspended.map(function(n){ return '<span class="label label-danger" style="margin:2px;">'+escHtml(n)+'</span>'; }).join(' ') + '</div>';
            }
            if(r.graced && r.graced.length){
                html += '<div><strong>Moved to Grace Period ('+r.graced.length+'):</strong><br>'
                    + r.graced.map(function(n){ return '<span class="label label-warning" style="margin:2px;">'+escHtml(n)+'</span>'; }).join(' ') + '</div>';
            }
            if(!r.suspended.length && !r.graced.length){
                html += '<p style="color:var(--t3);">All schools are within their subscription periods.</p>';
            }
        }
        $('#expireResultBody').html(html);
        $('#expireResultModal').modal('show');
        loadSubs();
    },'json').always(function(){
        $btn.prop('disabled',false).html('<i class="fa fa-refresh"></i> Run Expiry Check');
    });
});

$(function(){ loadSubs(); });
</script>
