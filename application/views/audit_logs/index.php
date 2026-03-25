<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
/* ── Audit Logs Module ─────────────────────────────────────────────── */
.al-wrap{padding:20px;max-width:1400px;margin:0 auto}
.al-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.al-header-title{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.al-header-title i{color:var(--gold);font-size:1.1rem}
.al-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.al-breadcrumb a{color:var(--gold);text-decoration:none}
.al-breadcrumb li+li::before{content:">";margin-right:6px;color:var(--t4)}

/* Tabs */
.al-tabs{display:flex;gap:4px;margin-bottom:20px;flex-wrap:wrap}
.al-tab{padding:8px 18px;border-radius:8px;font-size:12.5px;font-weight:600;font-family:var(--font-b);
    cursor:pointer;background:var(--bg3);color:var(--t3);border:1px solid var(--border);transition:all .15s var(--ease)}
.al-tab:hover{color:var(--t1);border-color:var(--gold-ring)}
.al-tab.active{background:var(--gold);color:#fff;border-color:var(--gold)}
.al-pane{display:none}.al-pane.active{display:block}

/* KPIs */
.al-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:20px}
.al-kpi-card{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:18px 16px;text-align:center}
.al-kpi-num{font-size:26px;font-weight:800;font-family:var(--font-b);line-height:1;color:var(--t1)}
.al-kpi-lbl{font-size:11px;color:var(--t3);margin-top:5px;text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m)}

/* Box */
.al-box{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:18px}
.al-box-head{font-size:14px;font-weight:700;color:var(--t1);font-family:var(--font-b);margin-bottom:14px;
    padding-bottom:10px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}

/* Table */
.al-table-wrap{max-height:520px;overflow-y:auto;border:1px solid var(--border);border-radius:8px}
.al-table{width:100%;border-collapse:collapse}
.al-table th{padding:10px 12px;background:var(--bg3);color:var(--t3);font-size:10.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m);border-bottom:2px solid var(--border);
    text-align:left;position:sticky;top:0;z-index:1}
.al-table td{padding:9px 12px;border-bottom:1px solid var(--border);color:var(--t1);font-size:12.5px}
.al-table tr:hover td{background:var(--gold-dim)}

/* Badges */
.al-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10.5px;font-weight:600;letter-spacing:.3px}
.al-b-teal{background:rgba(15,118,110,.12);color:var(--gold)}
.al-b-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.al-b-amber{background:rgba(217,119,6,.12);color:#d97706}
.al-b-green{background:rgba(22,163,74,.12);color:#16a34a}
.al-b-rose{background:rgba(220,38,38,.10);color:#dc2626}
.al-b-purple{background:rgba(139,92,246,.12);color:#8b5cf6}
.al-b-gray{background:rgba(107,114,128,.12);color:#6b7280}

/* Buttons */
.al-btn{padding:8px 18px;border-radius:7px;font-size:12.5px;font-weight:600;border:none;cursor:pointer;
    font-family:var(--font-b);transition:all .15s var(--ease);display:inline-flex;align-items:center;gap:6px}
.al-btn-p{background:var(--gold);color:#fff}.al-btn-p:hover{background:var(--gold2)}
.al-btn-s{background:var(--bg3);color:var(--t2);border:1px solid var(--border)}.al-btn-s:hover{border-color:var(--gold-ring)}
.al-btn-sm{padding:5px 12px;font-size:11.5px}

/* Filters */
.al-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px}
.al-fg{display:flex;flex-direction:column;gap:3px}
.al-fg label{font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m)}
.al-fg input,.al-fg select{padding:6px 10px;border:1px solid var(--border);background:var(--bg3);
    border-radius:6px;font-size:12px;color:var(--t1);font-family:var(--font-b);min-width:130px}
.al-fg input:focus,.al-fg select:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-ring)}

/* Module bar chart */
.al-bar{height:18px;border-radius:4px;background:var(--gold);transition:width .3s}
.al-bar-wrap{display:flex;align-items:center;gap:10px;margin-bottom:6px}
.al-bar-lbl{font-size:11.5px;font-family:var(--font-b);color:var(--t2);min-width:100px;text-align:right}
.al-bar-val{font-size:11px;font-family:var(--font-m);color:var(--t3);min-width:30px}

/* Empty */
.al-empty{text-align:center;padding:40px 20px;color:var(--t3);font-family:var(--font-b)}
.al-empty i{font-size:36px;display:block;margin-bottom:12px;opacity:.5}

/* Toast */
.al-toast{position:fixed;top:20px;right:20px;z-index:10000;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;font-family:var(--font-b);color:#fff;display:none;max-width:400px;box-shadow:0 8px 24px rgba(0,0,0,.15)}
.al-toast.success{background:#22c55e;display:block}.al-toast.error{background:#ef4444;display:block}
</style>

<div class="content-wrapper"><section class="content"><div class="al-wrap">

<div class="al-header"><div>
    <div class="al-header-title"><i class="fa fa-shield"></i> Audit Logs</div>
    <ol class="al-breadcrumb"><li><a href="<?= base_url('admin') ?>">Dashboard</a></li><li><a href="<?= base_url('admin_users') ?>">Admin Users</a></li><li>Audit Logs</li></ol>
</div>
<div style="display:flex;gap:8px;">
    <?php if (in_array($admin_role, ['Super Admin', 'School Super Admin', 'Admin'])): ?>
    <button class="al-btn al-btn-sm al-btn-s" onclick="AL.archive()"><i class="fa fa-archive"></i> Archive Old</button>
    <?php endif; ?>
    <button class="al-btn al-btn-sm al-btn-s" onclick="AL.refresh()"><i class="fa fa-refresh"></i> Refresh</button>
</div>
</div>

<!-- Tabs -->
<div class="al-tabs">
    <div class="al-tab active" data-tab="all"><i class="fa fa-list" style="margin-right:5px"></i>All Logs</div>
    <div class="al-tab" data-tab="user"><i class="fa fa-user" style="margin-right:5px"></i>User Activity</div>
    <div class="al-tab" data-tab="module"><i class="fa fa-cubes" style="margin-right:5px"></i>Module Activity</div>
</div>

<!-- ════════════════════════════════════════════ ALL LOGS ════ -->
<div class="al-pane active" id="pane-all">

    <div class="al-kpi" id="kpiRow">
        <div class="al-kpi-card"><div class="al-kpi-num" id="kpi-total">-</div><div class="al-kpi-lbl">Total Logs</div></div>
        <div class="al-kpi-card"><div class="al-kpi-num" id="kpi-today" style="color:var(--gold)">-</div><div class="al-kpi-lbl">Today</div></div>
        <div class="al-kpi-card"><div class="al-kpi-num" id="kpi-users" style="color:#3b82f6">-</div><div class="al-kpi-lbl">Active Users</div></div>
        <div class="al-kpi-card"><div class="al-kpi-num" id="kpi-modules" style="color:#8b5cf6">-</div><div class="al-kpi-lbl">Modules</div></div>
    </div>

    <div class="al-box">
        <div class="al-box-head">
            <span><i class="fa fa-filter" style="color:var(--gold);margin-right:6px"></i>Filters</span>
            <button class="al-btn al-btn-sm al-btn-s" onclick="AL.clearFilters()"><i class="fa fa-times"></i> Clear</button>
        </div>
        <div class="al-filters">
            <div class="al-fg">
                <label>Date From</label>
                <input type="date" id="fDateFrom">
            </div>
            <div class="al-fg">
                <label>Date To</label>
                <input type="date" id="fDateTo">
            </div>
            <div class="al-fg">
                <label>User</label>
                <select id="fUser"><option value="">All Users</option></select>
            </div>
            <div class="al-fg">
                <label>Module</label>
                <select id="fModule">
                    <option value="">All Modules</option>
                    <option value="SIS">SIS</option>
                    <option value="Fees">Fees</option>
                    <option value="Accounting">Accounting</option>
                    <option value="Attendance">Attendance</option>
                    <option value="Examinations">Examinations</option>
                    <option value="Results">Results</option>
                    <option value="HR">HR</option>
                    <option value="Communication">Communication</option>
                    <option value="Operations">Operations</option>
                    <option value="Certificates">Certificates</option>
                    <option value="AdminUsers">Admin Users</option>
                    <option value="Configuration">Configuration</option>
                    <option value="Academic">Academic</option>
                </select>
            </div>
            <div class="al-fg">
                <label>Action</label>
                <input type="text" id="fAction" placeholder="e.g. create, delete">
            </div>
            <div style="display:flex;align-items:flex-end;">
                <button class="al-btn al-btn-p al-btn-sm" onclick="AL.applyFilter()"><i class="fa fa-search"></i> Search</button>
            </div>
        </div>
    </div>

    <div class="al-box">
        <div class="al-box-head"><span>Log Entries</span><span id="logCount" style="font-size:11px;color:var(--t3);font-family:var(--font-m)"></span></div>
        <div class="al-table-wrap">
            <table class="al-table">
                <thead><tr>
                    <th>Time</th><th>User</th><th>Role</th><th>Module</th>
                    <th>Action</th><th>Entity</th><th>Description</th><th>IP</th>
                </tr></thead>
                <tbody id="logsTbody">
                    <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--t3)"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════ USER ACTIVITY ════ -->
<div class="al-pane" id="pane-user">
    <div class="al-box">
        <div class="al-box-head">
            <span><i class="fa fa-user" style="color:var(--gold);margin-right:6px"></i>Select User</span>
        </div>
        <div class="al-filters">
            <div class="al-fg">
                <label>Admin User</label>
                <select id="fUserActivity"><option value="">Select a user...</option></select>
            </div>
            <div style="display:flex;align-items:flex-end">
                <button class="al-btn al-btn-p al-btn-sm" onclick="AL.loadUserActivity()"><i class="fa fa-search"></i> Load Activity</button>
            </div>
        </div>
    </div>

    <div id="userSummaryBox" style="display:none">
        <div class="al-kpi" id="userKpi"></div>
    </div>

    <div class="al-box">
        <div class="al-box-head"><span>User Activity Log</span><span id="userLogCount" style="font-size:11px;color:var(--t3);font-family:var(--font-m)"></span></div>
        <div class="al-table-wrap">
            <table class="al-table">
                <thead><tr>
                    <th>Time</th><th>Module</th><th>Action</th><th>Entity</th><th>Description</th><th>IP</th>
                </tr></thead>
                <tbody id="userLogsTbody">
                    <tr><td colspan="6" class="al-empty"><i class="fa fa-hand-pointer-o"></i> Select a user to view their activity</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════ MODULE ACTIVITY ════ -->
<div class="al-pane" id="pane-module">
    <div class="al-box">
        <div class="al-box-head"><span><i class="fa fa-bar-chart" style="color:var(--gold);margin-right:6px"></i>Activity by Module</span></div>
        <div id="moduleChart" style="padding:10px 0">
            <p style="text-align:center;color:var(--t3);padding:20px"><i class="fa fa-spinner fa-spin"></i> Loading...</p>
        </div>
    </div>

    <div class="al-box">
        <div class="al-box-head"><span>Top Active Users</span></div>
        <div class="al-table-wrap" style="max-height:300px">
            <table class="al-table">
                <thead><tr><th>User</th><th>Actions</th><th>Activity Bar</th></tr></thead>
                <tbody id="topUsersTbody">
                    <tr><td colspan="3" style="text-align:center;padding:20px;color:var(--t3)"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

</div></section></div>

<div class="al-toast" id="alToast"></div>

<script>
document.addEventListener('DOMContentLoaded', function(){
'use strict';

var B   = typeof BASE_URL !== 'undefined' ? BASE_URL : <?= json_encode(base_url()) ?>;
var AL  = window.AL = {};
var _stats = {};

function esc(s){ return $('<div>').text(s||'').html(); }
function ts(s){ return (s||'').substring(0,19).replace('T',' '); }
function csrf(extra){ var o={}; if(typeof csrfName!=='undefined') o[csrfName]=csrfToken; return $.extend(o,extra||{}); }

AL.toast = function(msg,ok){
    var t=$('#alToast');
    t.text(msg).attr('class','al-toast '+(ok?'success':'error'));
    setTimeout(function(){t.attr('class','al-toast')},3000);
};

/* ── Tab switching ──────────────────────────────────────────── */
$('.al-tab').on('click', function(){
    $('.al-tab').removeClass('active');
    $(this).addClass('active');
    var t = $(this).attr('data-tab');
    $('.al-pane').removeClass('active');
    $('#pane-'+t).addClass('active');
});

/* ── Module badge color map ─────────────────────────────────── */
var modColors = {
    'SIS':'al-b-teal','Fees':'al-b-green','Accounting':'al-b-amber',
    'Attendance':'al-b-blue','Examinations':'al-b-purple','Results':'al-b-purple',
    'HR':'al-b-rose','Communication':'al-b-blue','Operations':'al-b-amber',
    'Certificates':'al-b-teal','AdminUsers':'al-b-rose','Configuration':'al-b-gray',
    'Academic':'al-b-purple'
};
function modBadge(m){ return '<span class="al-badge '+(modColors[m]||'al-b-gray')+'">'+esc(m)+'</span>'; }

/* ══════════════════════════════════ STATS ═══════════════════ */
AL.loadStats = function(){
    $.post(B+'audit_logs/get_stats', csrf(), function(r){
        if(r.status!=='success') return;
        _stats = r;
        $('#kpi-total').text(r.total||0);
        $('#kpi-today').text(r.today||0);
        var userCount = r.admin_list ? Object.keys(r.admin_list).length : 0;
        var modCount  = r.by_module ? Object.keys(r.by_module).length : 0;
        $('#kpi-users').text(userCount);
        $('#kpi-modules').text(modCount);

        // Populate user dropdowns
        var opts = '<option value="">All Users</option>';
        var opts2 = '<option value="">Select a user...</option>';
        if(r.admin_list){
            $.each(r.admin_list, function(uid, name){
                opts += '<option value="'+esc(uid)+'">'+esc(name)+' ('+esc(uid)+')</option>';
                opts2 += '<option value="'+esc(uid)+'">'+esc(name)+' ('+esc(uid)+')</option>';
            });
        }
        $('#fUser').html(opts);
        $('#fUserActivity').html(opts2);

        // Module chart
        renderModuleChart(r.by_module||{});

        // Top users
        renderTopUsers(r.by_user||{}, r.admin_list||{});
    },'json');
};

function renderModuleChart(byModule){
    var keys = Object.keys(byModule);
    if(!keys.length){
        $('#moduleChart').html('<p style="text-align:center;color:var(--t3);padding:20px">No module data yet.</p>');
        return;
    }
    var max = Math.max.apply(null, keys.map(function(k){return byModule[k]}));
    var html = '';
    keys.forEach(function(m){
        var pct = max>0 ? Math.round(byModule[m]/max*100) : 0;
        html += '<div class="al-bar-wrap">'
            +'<div class="al-bar-lbl">'+esc(m)+'</div>'
            +'<div style="flex:1;background:var(--bg3);border-radius:4px;overflow:hidden">'
            +'<div class="al-bar" style="width:'+pct+'%"></div></div>'
            +'<div class="al-bar-val">'+byModule[m]+'</div></div>';
    });
    $('#moduleChart').html(html);
}

function renderTopUsers(byUser, adminList){
    var keys = Object.keys(byUser);
    if(!keys.length){
        $('#topUsersTbody').html('<tr><td colspan="3" style="text-align:center;padding:20px;color:var(--t3)">No user data yet.</td></tr>');
        return;
    }
    var max = byUser[keys[0]]||1;
    var html = keys.slice(0,10).map(function(uid){
        var name = adminList[uid]||uid;
        var cnt  = byUser[uid];
        var pct  = Math.round(cnt/max*100);
        return '<tr><td><strong>'+esc(name)+'</strong><div style="font-size:10px;color:var(--t3)">'+esc(uid)+'</div></td>'
            +'<td style="font-family:var(--font-m);font-weight:700">'+cnt+'</td>'
            +'<td><div style="background:var(--bg3);border-radius:4px;overflow:hidden"><div class="al-bar" style="width:'+pct+'%;height:14px"></div></div></td></tr>';
    }).join('');
    $('#topUsersTbody').html(html);
}

/* ══════════════════════════════════ ALL LOGS ════════════════ */
AL.loadLogs = function(){
    $('#logsTbody').html('<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--t3)"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>');
    $.post(B+'audit_logs/get_logs', csrf(), function(r){
        if(r.status!=='success'){ AL.toast(r.message||'Failed',''); return; }
        renderLogs(r.logs||[], r.total||0);
    },'json').fail(function(){ AL.toast('Network error loading logs',''); });
};

function renderLogs(logs, total){
    $('#logCount').text(total+' total'+(logs.length<total ? ' (showing '+logs.length+')':''));
    if(!logs.length){
        $('#logsTbody').html('<tr><td colspan="8" class="al-empty"><i class="fa fa-shield"></i> No audit logs recorded yet</td></tr>');
        return;
    }
    var html = logs.map(function(l){
        return '<tr>'
            +'<td style="font-size:11px;font-family:var(--font-m);color:var(--t3);white-space:nowrap">'+esc(ts(l.timestamp))+'</td>'
            +'<td><strong>'+esc(l.userName||l.userId||'')+'</strong><div style="font-size:10px;color:var(--t3)">'+esc(l.userId||'')+'</div></td>'
            +'<td><span class="al-badge al-b-blue">'+esc(l.userRole||'')+'</span></td>'
            +'<td>'+modBadge(l.module||'')+'</td>'
            +'<td style="font-size:12px;font-family:var(--font-m)">'+esc(l.action||'')+'</td>'
            +'<td style="font-size:11px;font-family:var(--font-m);color:var(--t3)">'+esc(l.entityId||'')+'</td>'
            +'<td style="font-size:12px;max-width:220px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="'+esc(l.description||'')+'">'+esc(l.description||'')+'</td>'
            +'<td style="font-size:11px;font-family:var(--font-m);color:var(--t3)">'+esc(l.ipAddress||'')+'</td>'
            +'</tr>';
    }).join('');
    $('#logsTbody').html(html);
}

/* ══════════════════════════════════ FILTER ══════════════════ */
AL.applyFilter = function(){
    var params = {
        date_from: $('#fDateFrom').val()||'',
        date_to:   $('#fDateTo').val()||'',
        user_id:   $('#fUser').val()||'',
        module:    $('#fModule').val()||'',
        action:    $('#fAction').val()||''
    };
    $('#logsTbody').html('<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--t3)"><i class="fa fa-spinner fa-spin"></i> Filtering...</td></tr>');
    $.post(B+'audit_logs/filter_logs', csrf(params), function(r){
        if(r.status!=='success'){ AL.toast(r.message||'Filter failed',''); return; }
        renderLogs(r.logs||[], r.total||0);
    },'json').fail(function(){ AL.toast('Network error',''); });
};

AL.clearFilters = function(){
    $('#fDateFrom,#fDateTo,#fAction').val('');
    $('#fUser,#fModule').val('');
    AL.loadLogs();
};

/* ══════════════════════════════════ USER ACTIVITY ═══════════ */
AL.loadUserActivity = function(){
    var uid = $('#fUserActivity').val();
    if(!uid){ AL.toast('Select a user first',''); return; }

    $('#userLogsTbody').html('<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--t3)"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>');

    $.post(B+'audit_logs/get_user_activity', csrf({user_id:uid}), function(r){
        if(r.status!=='success'){ AL.toast(r.message||'Failed',''); return; }

        var logs = r.logs||[];
        $('#userLogCount').text(r.total+' entries');

        // Summary KPIs
        var summary = r.summary||{};
        var kpiHtml = '';
        $.each(summary, function(mod, count){
            kpiHtml += '<div class="al-kpi-card"><div class="al-kpi-num" style="font-size:22px;color:var(--gold)">'+count+'</div><div class="al-kpi-lbl">'+esc(mod)+'</div></div>';
        });
        if(kpiHtml){
            $('#userKpi').html(kpiHtml);
            $('#userSummaryBox').show();
        } else {
            $('#userSummaryBox').hide();
        }

        if(!logs.length){
            $('#userLogsTbody').html('<tr><td colspan="6" class="al-empty"><i class="fa fa-shield"></i> No activity found for this user</td></tr>');
            return;
        }
        var html = logs.map(function(l){
            return '<tr>'
                +'<td style="font-size:11px;font-family:var(--font-m);color:var(--t3);white-space:nowrap">'+esc(ts(l.timestamp))+'</td>'
                +'<td>'+modBadge(l.module||'')+'</td>'
                +'<td style="font-size:12px;font-family:var(--font-m)">'+esc(l.action||'')+'</td>'
                +'<td style="font-size:11px;font-family:var(--font-m);color:var(--t3)">'+esc(l.entityId||'')+'</td>'
                +'<td style="font-size:12px">'+esc(l.description||'')+'</td>'
                +'<td style="font-size:11px;font-family:var(--font-m);color:var(--t3)">'+esc(l.ipAddress||'')+'</td>'
                +'</tr>';
        }).join('');
        $('#userLogsTbody').html(html);
    },'json').fail(function(){ AL.toast('Network error',''); });
};

/* ══════════════════════════════════ ARCHIVE ═════════════════ */
AL.archive = function(){
    if(!confirm('Archive old logs beyond the 10,000 limit? Archived logs move to AuditArchive.')) return;
    $.post(B+'audit_logs/archive_old', csrf(), function(r){
        if(r.status==='success'){ AL.toast(r.message,true); AL.refresh(); }
        else { AL.toast(r.message||'Failed',''); }
    },'json');
};

/* ══════════════════════════════════ REFRESH ═════════════════ */
AL.refresh = function(){
    AL.loadStats();
    AL.loadLogs();
};

/* ── Init ───────────────────────────────────────────────────── */
AL.refresh();

});
</script>
