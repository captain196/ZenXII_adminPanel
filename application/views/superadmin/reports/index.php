
<section class="content-header">
    <h1><i class="fa fa-bar-chart" style="color:var(--sa3);margin-right:10px;font-size:20px;"></i>Global Reports</h1>
    <ol class="breadcrumb">
        <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
        <li class="active">Reports</li>
    </ol>
</section>

<section class="content" style="padding:20px 24px;">

    <!-- Tab Nav -->
    <div style="display:flex;gap:0;margin-bottom:24px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;overflow:hidden;width:fit-content;">
        <?php foreach(['students'=>'Students','revenue'=>'Revenue','activity'=>'Activity','plans'=>'Plan Distribution'] as $tab => $label): ?>
        <button class="sa-report-tab btn btn-default" data-tab="<?= $tab ?>"
            style="border-radius:0;border:none;border-right:1px solid var(--border);padding:9px 18px;font-size:12.5px;">
            <?= $label ?>
        </button>
        <?php endforeach; ?>
    </div>

    <!-- Students Tab -->
    <div id="tab-students" class="sa-report-pane">
        <div class="box box-primary">
            <div class="box-header">
                <i class="fa fa-users" style="color:var(--sa3);margin-right:8px;"></i>
                <span class="box-title">Student Distribution Across Schools</span>
                <button class="btn btn-default btn-xs" style="float:right;" id="loadStudentsBtn">
                    <i class="fa fa-refresh" id="studentsIcon"></i> Load
                </button>
            </div>
            <div class="box-body" style="padding:0 !important;">
                <table class="table sa-table-simple" id="studentsTable" style="margin:0;">
                    <thead><tr>
                        <th>#</th><th>School</th><th>City</th><th>Plan</th><th>Status</th>
                        <th>Students</th><th>Staff</th><th>Last Updated</th>
                    </tr></thead>
                    <tbody id="studentsTbody"></tbody>
                    <tfoot><tr>
                        <td colspan="5" style="text-align:right;font-weight:700;color:var(--t2);">Total</td>
                        <td id="studentsTotal" style="font-weight:700;color:var(--sa3);font-family:var(--font-d);font-size:16px;"></td>
                        <td colspan="2"></td>
                    </tr></tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Revenue Tab -->
    <div id="tab-revenue" class="sa-report-pane" style="display:none;">
        <div class="box box-success">
            <div class="box-header">
                <i class="fa fa-money" style="color:var(--green);margin-right:8px;"></i>
                <span class="box-title">Revenue Summary</span>
                <button class="btn btn-default btn-xs" style="float:right;" id="loadRevenueBtn">
                    <i class="fa fa-refresh" id="revenueIcon"></i> Load
                </button>
            </div>
            <div class="box-body" style="padding:0 !important;">
                <table class="table sa-table-simple" id="revenueTable" style="margin:0;">
                    <thead><tr>
                        <th>#</th><th>School</th><th>Plan</th><th>Expiry</th><th>Sub Status</th><th>Revenue (₹)</th>
                    </tr></thead>
                    <tbody id="revenueTbody"></tbody>
                    <tfoot><tr>
                        <td colspan="5" style="text-align:right;font-weight:700;color:var(--t2);">Total Revenue</td>
                        <td id="revenueTotal" style="font-weight:700;color:var(--green);font-family:var(--font-d);font-size:16px;"></td>
                    </tr></tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Activity Tab -->
    <div id="tab-activity" class="sa-report-pane" style="display:none;">
        <div class="box">
            <div class="box-header">
                <i class="fa fa-history" style="color:var(--sa3);margin-right:8px;"></i>
                <span class="box-title">System Activity</span>
                <div style="float:right;display:flex;gap:8px;align-items:center;">
                    <input type="date" class="form-control" id="actFrom" value="<?= date('Y-m-d', strtotime('-7 days')) ?>" style="height:30px;padding:3px 8px;font-size:12px;width:130px;">
                    <input type="date" class="form-control" id="actTo"   value="<?= date('Y-m-d') ?>"                style="height:30px;padding:3px 8px;font-size:12px;width:130px;">
                    <button class="btn btn-default btn-xs" id="loadActivityBtn"><i class="fa fa-search"></i> Load</button>
                </div>
            </div>
            <div class="box-body" id="activitySummary" style="display:none;padding:12px 18px !important;">
                <div id="actionBreakdown" style="display:flex;flex-wrap:wrap;gap:6px;"></div>
            </div>
            <div class="box-body" style="padding:0 !important;">
                <table class="table sa-table-simple" id="activityTable" style="margin:0;">
                    <thead><tr><th>Time</th><th>SA User</th><th>Action</th><th>School</th><th>IP</th></tr></thead>
                    <tbody id="activityTbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Plan Distribution Tab -->
    <div id="tab-plans" class="sa-report-pane" style="display:none;">
        <div class="row">
            <div class="col-md-5">
                <div class="box">
                    <div class="box-header">
                        <i class="fa fa-pie-chart" style="color:var(--sa3);margin-right:8px;"></i>
                        <span class="box-title">Plan Distribution</span>
                        <button class="btn btn-default btn-xs" style="float:right;" id="loadPlansBtn"><i class="fa fa-refresh" id="plansIcon"></i> Load</button>
                    </div>
                    <div class="box-body">
                        <div id="planDistChart" style="height:200px;"></div>
                        <table class="table" id="planDistTable" style="margin-top:12px;">
                            <thead><tr><th>Plan</th><th>Schools</th></tr></thead>
                            <tbody id="planDistTbody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

</section>

<script src="<?= base_url() ?>tools/bower_components/raphael/raphael.min.js"></script>
<script src="<?= base_url() ?>tools/bower_components/morris.js/morris.min.js"></script>
<link rel="stylesheet" href="<?= base_url() ?>tools/bower_components/morris.js/morris.css">

<script>
$(function(){
    // Tab switching
    $('.sa-report-tab').first().addClass('active').css('background','var(--sa-dim)').css('color','var(--sa3)');

    $('.sa-report-tab').on('click', function(){
        $('.sa-report-tab').css('background','').css('color','').removeClass('active');
        $(this).addClass('active').css('background','var(--sa-dim)').css('color','var(--sa3)');
        var tab = $(this).data('tab');
        $('.sa-report-pane').hide();
        $('#tab-'+tab).show();
    });

    var dtStudents, dtRevenue, dtActivity;

    // Students
    $('#loadStudentsBtn').on('click', function(){
        $(this).prop('disabled',true).find('i').addClass('fa-spin');
        $.ajax({ url: BASE_URL+'superadmin/reports/students', type:'POST',
            success: function(r){
                if(r.status!=='success'){ saToast(r.message,'error'); return; }
                var html = '';
                (r.rows||[]).forEach(function(row,i){
                    var st = row.status==='active'?'label-success':'label-danger';
                    html += '<tr><td>'+(i+1)+'</td><td><strong>'+esc(row.name)+'</strong></td><td>'+esc(row.city)+'</td>'
                          + '<td><span class="label label-warning">'+esc(row.plan_name)+'</span></td>'
                          + '<td><span class="label '+st+'">'+esc(row.status)+'</span></td>'
                          + '<td style="font-weight:700;color:var(--sa3);">'+(row.students||0).toLocaleString('en-IN')+'</td>'
                          + '<td>'+(row.staff||0)+'</td>'
                          + '<td style="font-size:11px;color:var(--t3);">'+esc(row.last_updated||'')+'</td></tr>';
                });
                if(dtStudents){ dtStudents.destroy(); dtStudents = null; }
                $('#studentsTbody').html(html);
                $('#studentsTotal').text((r.total||0).toLocaleString('en-IN'));
                dtStudents = new DataTable('#studentsTable',{paging:true,searching:true,ordering:true,responsive:true,lengthMenu:[10,25,50],language:{emptyTable:'No data'}});
            },
            error: function(){ saToast('Failed.','error'); },
            complete: function(){ $('#loadStudentsBtn').prop('disabled',false).find('i').removeClass('fa-spin'); }
        });
    });

    // Revenue
    $('#loadRevenueBtn').on('click', function(){
        $(this).prop('disabled',true).find('i').addClass('fa-spin');
        $.ajax({ url: BASE_URL+'superadmin/reports/revenue', type:'POST',
            success: function(r){
                if(r.status!=='success'){ saToast(r.message,'error'); return; }
                var html='';
                var ss = {active:'label-success',grace:'label-warning',expired:'label-danger',suspended:'label-default'};
                (r.rows||[]).forEach(function(row,i){
                    html += '<tr><td>'+(i+1)+'</td><td>'+esc(row.name)+'</td><td><span class="label label-warning">'+esc(row.plan_name)+'</span></td>'
                          + '<td>'+esc(row.expiry_date)+'</td><td><span class="label '+(ss[row.sub_status]||'label-default')+'">'+esc(row.sub_status)+'</span></td>'
                          + '<td style="font-weight:700;color:var(--green);">₹'+(parseFloat(row.revenue)||0).toLocaleString('en-IN')+'</td></tr>';
                });
                if(dtRevenue){ dtRevenue.destroy(); dtRevenue = null; }
                $('#revenueTbody').html(html);
                $('#revenueTotal').text('₹'+(parseFloat(r.total_revenue)||0).toLocaleString('en-IN'));
                dtRevenue = new DataTable('#revenueTable',{paging:true,searching:true,responsive:true,lengthMenu:[10,25,50],language:{emptyTable:'No data'}});
            },
            error:function(){ saToast('Failed.','error'); },
            complete:function(){ $('#loadRevenueBtn').prop('disabled',false).find('i').removeClass('fa-spin'); }
        });
    });

    // Activity
    $('#loadActivityBtn').on('click', function(){
        $(this).prop('disabled',true).find('i').addClass('fa-spin');
        $.ajax({ url: BASE_URL+'superadmin/reports/activity', type:'POST',
            data:{ date_from:$('#actFrom').val(), date_to:$('#actTo').val() },
            success:function(r){
                if(r.status!=='success'){ saToast(r.message,'error'); return; }
                // Action breakdown
                var bdHtml='';
                $.each(r.action_map||{},function(action,count){
                    bdHtml+='<span style="padding:3px 9px;background:var(--sa-dim);border:1px solid var(--sa-ring);border-radius:6px;font-size:11px;font-family:var(--font-m);color:var(--sa3);">'
                           +esc(action)+' <strong>'+count+'</strong></span>';
                });
                $('#actionBreakdown').html(bdHtml||'<span style="color:var(--t3);font-size:12px;">No actions found.</span>');
                $('#activitySummary').show();
                var html='';
                (r.rows||[]).forEach(function(row){
                    html+='<tr><td style="font-family:var(--font-m);font-size:11px;color:var(--t3);">'+(row.timestamp||'').substring(11,19)+'</td>'
                         +'<td>'+esc(row.sa_name||'')+'</td>'
                         +'<td><span style="color:var(--sa3);font-family:var(--font-m);font-size:12px;">'+esc(row.action||'')+'</span></td>'
                         +'<td style="font-size:11px;color:var(--t3);">'+esc(row.school_uid||'—')+'</td>'
                         +'<td style="font-size:11px;color:var(--t3);">'+esc(row.ip||'')+'</td></tr>';
                });
                if(dtActivity){ dtActivity.destroy(); dtActivity = null; }
                $('#activityTbody').html(html);
                dtActivity = new DataTable('#activityTable',{paging:true,searching:true,responsive:true,lengthMenu:[25,50,100],language:{emptyTable:'No activity found'}});
            },
            error:function(){ saToast('Failed.','error'); },
            complete:function(){ $('#loadActivityBtn').prop('disabled',false).find('i').removeClass('fa-spin'); }
        });
    });

    // Plan distribution
    $('#loadPlansBtn').on('click', function(){
        $(this).prop('disabled',true).find('i').addClass('fa-spin');
        $.ajax({ url: BASE_URL+'superadmin/reports/plans_distribution', type:'POST',
            success:function(r){
                if(r.status!=='success'){ saToast(r.message,'error'); return; }
                var html='', chartData=[];
                var colors=['#7c3aed','#6d28d9','#4c1d95','#a78bfa','#c4b5fd','#ddd6fe'];
                (r.rows||[]).forEach(function(row,i){
                    html+='<tr><td>'+esc(row.plan)+'</td><td><strong style="color:var(--sa3);">'+row.count+'</strong></td></tr>';
                    chartData.push({label:row.plan, value:row.count, color:colors[i%colors.length]});
                });
                $('#planDistTbody').html(html);
                $('#planDistChart').empty();
                if(chartData.length && typeof Morris!=='undefined'){
                    Morris.Donut({element:'planDistChart', data:chartData, colors:chartData.map(function(d){return d.color;}),
                        labelColor:'#c4b5fd', backgroundColor:'transparent', borderWidth:0});
                }
            },
            error:function(){ saToast('Failed.','error'); },
            complete:function(){ $('#loadPlansBtn').prop('disabled',false).find('i').removeClass('fa-spin'); }
        });
    });

    function esc(s){ return $('<div>').text(s||'').html(); }
});
</script>
