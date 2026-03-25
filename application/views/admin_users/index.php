<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
/* ── Admin Users Module ──────────────────────────────────────────────── */
:root{--green:#16a34a;}
.content-wrapper { margin-left:var(--sw,248px) !important; margin-top:var(--hh,58px) !important; min-height:calc(100vh - var(--hh,58px)) !important; }

.au-tabs{display:flex;gap:4px;margin-bottom:20px;flex-wrap:wrap;}
.au-tab{padding:8px 18px;border-radius:8px;font-size:12.5px;font-weight:600;font-family:var(--font-b);
    cursor:pointer;background:var(--bg3);color:var(--t3);border:1px solid var(--border);transition:all .15s var(--ease);}
.au-tab:hover{color:var(--t1);border-color:var(--gold-ring);}
.au-tab.active{background:var(--gold);color:#fff;border-color:var(--gold);}
.au-pane{display:none;}.au-pane.active{display:block;}

/* Cards */
.au-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:14px;margin-bottom:22px;}
.au-kpi-card{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:18px 16px;text-align:center;}
.au-kpi-num{font-size:28px;font-weight:800;font-family:var(--font-b);line-height:1;color:var(--t1);}
.au-kpi-lbl{font-size:11px;color:var(--t3);margin-top:5px;text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m);}

/* Forms */
.au-fg{display:flex;flex-direction:column;gap:4px;margin-bottom:12px;}
.au-fg label{font-size:11px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m);}
.au-fg input,.au-fg select,.au-fg textarea{padding:8px 12px;border:1px solid var(--border);background:var(--bg3);
    border-radius:6px;font-size:13px;color:var(--t1);font-family:var(--font-b);transition:border-color .2s,box-shadow .2s;}
.au-fg input:focus,.au-fg select:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-ring);}

/* Buttons */
.au-btn{padding:8px 18px;border-radius:7px;font-size:12.5px;font-weight:600;border:none;cursor:pointer;
    font-family:var(--font-b);transition:all .15s var(--ease);display:inline-flex;align-items:center;gap:6px;}
.au-btn:disabled{opacity:.5;cursor:not-allowed;}
.au-btn-p{background:var(--gold);color:#fff;}.au-btn-p:hover{background:var(--gold2);}
.au-btn-s{background:var(--bg3);color:var(--t2);border:1px solid var(--border);}.au-btn-s:hover{border-color:var(--gold-ring);}
.au-btn-d{background:transparent;color:#dc2626;border:1px solid rgba(220,38,38,.2);}.au-btn-d:hover{background:rgba(220,38,38,.06);}
.au-btn-sm{padding:5px 12px;font-size:11.5px;}

/* Tables */
.au-table{width:100%;border-collapse:collapse;}
.au-table th{padding:10px 12px;background:var(--bg3);color:var(--t3);font-size:10.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m);border-bottom:2px solid var(--border);
    text-align:left;position:sticky;top:0;z-index:1;}
.au-table td{padding:9px 12px;border-bottom:1px solid var(--border);color:var(--t1);font-size:12.5px;}
.au-table tr:hover td{background:var(--gold-dim);}
.au-wrap{max-height:500px;overflow-y:auto;border:1px solid var(--border);border-radius:8px;}

/* Badges */
.au-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10.5px;font-weight:600;letter-spacing:.3px;}
.au-b-green{background:rgba(22,163,74,.12);color:#16a34a;}
.au-b-red{background:rgba(220,38,38,.10);color:#dc2626;}
.au-b-blue{background:rgba(59,130,246,.12);color:#3b82f6;}
.au-b-amber{background:rgba(217,119,6,.12);color:#d97706;}
.au-b-gray{background:rgba(107,114,128,.12);color:#6b7280;}

/* Permission chips */
.au-perm-grid{display:flex;flex-wrap:wrap;gap:6px;margin-top:6px;}
.au-perm-chip{padding:4px 10px;border-radius:6px;font-size:11px;font-family:var(--font-m);
    cursor:pointer;border:1px solid var(--border);background:var(--bg3);color:var(--t3);transition:all .15s;}
.au-perm-chip.selected{background:var(--gold-dim);color:var(--gold);border-color:var(--gold-ring);font-weight:600;}
.au-perm-chip:hover{border-color:var(--gold-ring);}

/* Log filter active state */
.log-filter.active{background:var(--gold) !important;color:#fff !important;border-color:var(--gold) !important;font-weight:600;}

/* Section box */
.au-box{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:18px;}
.au-box-head{font-size:14px;font-weight:700;color:var(--t1);font-family:var(--font-b);margin-bottom:14px;
    padding-bottom:10px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:8px;}
</style>

<div class="content-wrapper">
<section class="content-header">
    <h1 style="font-family:var(--font-b); font-weight:700; color:var(--t1); font-size:1.3rem;">
    <i class="fa fa-users" style="color:var(--gold); margin-right:8px;"></i>
    Admin Users
</h1>
    <ol class="breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li class="active">Admin Users</li>
    </ol>
</section>

<section class="content" style="padding:18px 24px;">

    <!-- Tabs -->
    <div class="au-tabs">
        <div class="au-tab active" data-tab="dashboard"><i class="fa fa-dashboard" style="margin-right:5px;"></i>Dashboard</div>
        <div class="au-tab" data-tab="admins"><i class="fa fa-user-circle" style="margin-right:5px;"></i>Admin Users</div>
        <div class="au-tab" data-tab="roles"><i class="fa fa-key" style="margin-right:5px;"></i>Roles &amp; Permissions</div>
        <div class="au-tab" data-tab="logs"><i class="fa fa-history" style="margin-right:5px;"></i>Login Activity</div>
    </div>

    <!-- ═══════════════════════════════════════════ DASHBOARD ═══ -->
    <div class="au-pane active" id="pane-dashboard">

        <div class="au-kpi" id="kpiRow">
            <div class="au-kpi-card"><div class="au-kpi-num" id="kpi-total">-</div><div class="au-kpi-lbl">Total Admins</div></div>
            <div class="au-kpi-card"><div class="au-kpi-num" id="kpi-active" style="color:var(--green);">-</div><div class="au-kpi-lbl">Active</div></div>
            <div class="au-kpi-card"><div class="au-kpi-num" id="kpi-disabled" style="color:#dc2626;">-</div><div class="au-kpi-lbl">Disabled</div></div>
        </div>

        <div class="au-box">
            <div class="au-box-head"><i class="fa fa-history" style="color:var(--gold);"></i> Recent Login Activity</div>
            <div class="au-wrap" style="max-height:320px;">
                <table class="au-table">
                    <thead><tr><th>Time</th><th>Admin</th><th>IP</th><th>Device</th><th>Status</th></tr></thead>
                    <tbody id="dashRecentBody">
                        <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--t3);"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════ ADMIN USERS ═══ -->
    <div class="au-pane" id="pane-admins">

        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
            <input type="text" id="adminSearch" placeholder="Search by name, email, role..." class="form-control"
                style="width:260px;font-size:12.5px;height:34px;">
            <?php if (in_array($admin_role, ['Super Admin', 'School Super Admin', 'Admin', 'Principal'])): ?>
            <div style="margin-left:auto;">
                <button class="au-btn au-btn-p" id="addAdminBtn"><i class="fa fa-plus"></i> Add Admin</button>
            </div>
            <?php endif; ?>
        </div>

        <div class="au-wrap">
            <table class="au-table" id="adminsTable">
                <thead><tr>
                    <th>Name</th><th>Email</th><th>Phone</th><th>Role</th>
                    <th>Status</th><th>Created</th><th>Last Login</th><th style="width:140px;">Actions</th>
                </tr></thead>
                <tbody id="adminsTbody">
                    <tr><td colspan="8" style="text-align:center;padding:24px;color:var(--t3);"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════ ROLES ═══ -->
    <div class="au-pane" id="pane-roles">

        <div class="row">
            <!-- Roles list -->
            <div class="col-md-5">
                <div class="au-box">
                    <div class="au-box-head">
                        <i class="fa fa-shield" style="color:var(--gold);"></i> Roles
                        <?php if (in_array($admin_role, ['Super Admin', 'School Super Admin', 'Admin'])): ?>
                        <button class="au-btn au-btn-p au-btn-sm" style="margin-left:auto;" id="addRoleBtn"><i class="fa fa-plus"></i> New Role</button>
                        <?php endif; ?>
                    </div>
                    <div id="rolesListWrap" style="max-height:420px;overflow-y:auto;">
                        <p style="text-align:center;color:var(--t3);padding:20px;"><i class="fa fa-spinner fa-spin"></i></p>
                    </div>
                </div>
            </div>

            <!-- Role editor -->
            <div class="col-md-7">
                <div class="au-box" id="roleEditorBox" style="display:none;">
                    <div class="au-box-head">
                        <i class="fa fa-pencil" style="color:var(--gold);"></i>
                        <span id="roleEditorTitle">Edit Role</span>
                    </div>
                    <form id="roleForm" autocomplete="off">
                        <input type="hidden" id="roleOrigName" value="">
                        <div class="row">
                            <div class="col-sm-6">
                                <div class="au-fg">
                                    <label>Role Name (key)</label>
                                    <input type="text" id="roleNameInput" maxlength="40" placeholder="e.g. Librarian">
                                </div>
                            </div>
                            <div class="col-sm-6">
                                <div class="au-fg">
                                    <label>Display Label</label>
                                    <input type="text" id="roleLabelInput" maxlength="60" placeholder="e.g. School Librarian">
                                </div>
                            </div>
                        </div>
                        <div class="au-fg">
                            <label>Description</label>
                            <input type="text" id="roleDescInput" maxlength="120" placeholder="Brief description of this role">
                        </div>
                        <div class="au-fg">
                            <label>Module Permissions</label>
                            <div class="au-perm-grid" id="permGrid"></div>
                        </div>
                        <div style="display:flex;gap:8px;margin-top:16px;">
                            <button type="submit" class="au-btn au-btn-p"><i class="fa fa-check"></i> Save Role</button>
                            <button type="button" class="au-btn au-btn-s" id="cancelRoleBtn">Cancel</button>
                        </div>
                    </form>
                </div>
                <div id="roleEditorPlaceholder" style="text-align:center;color:var(--t3);padding:60px 20px;font-size:13px;">
                    <i class="fa fa-hand-pointer-o" style="font-size:24px;display:block;margin-bottom:10px;color:var(--gold);"></i>
                    Select a role to edit its permissions, or create a new one.
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════ LOGIN ACTIVITY ═══ -->
    <div class="au-pane" id="pane-logs">

        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
            <div style="display:flex;gap:5px;">
                <button class="au-btn au-btn-sm au-btn-s log-filter active" data-filter="all">All</button>
                <button class="au-btn au-btn-sm au-btn-s log-filter" data-filter="success">Success</button>
                <button class="au-btn au-btn-sm au-btn-s log-filter" data-filter="failed" style="color:#dc2626;">Failed</button>
            </div>
            <input type="text" id="logSearch" placeholder="Filter admin, IP..." class="form-control"
                style="width:200px;font-size:12.5px;height:30px;margin-left:auto;">
        </div>

        <div class="au-wrap">
            <table class="au-table">
                <thead><tr><th>Time</th><th>Admin</th><th>IP Address</th><th>Device</th><th>Status</th></tr></thead>
                <tbody id="logsTbody">
                    <tr><td colspan="5" style="text-align:center;padding:24px;color:var(--t3);"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
                </tbody>
            </table>
        </div>
        <div id="logsMeta" style="font-size:11.5px;color:var(--t3);font-family:var(--font-m);margin-top:8px;"></div>
    </div>

</section>

<!-- ═══════════════════════════════════ ADMIN MODAL ═══ -->
<div class="modal fade" id="adminModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content" style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;">
            <div class="modal-header" style="border-color:var(--border);">
                <button type="button" class="close" data-dismiss="modal" style="color:var(--t3);">&times;</button>
                <h4 class="modal-title" id="adminModalTitle" style="font-family:var(--font-b);color:var(--t1);">
                    <i class="fa fa-user-plus" style="color:var(--gold);margin-right:8px;"></i>Add Admin
                </h4>
            </div>
            <form id="adminForm" autocomplete="off">
                <div class="modal-body" style="padding:20px;">
                    <input type="hidden" id="editAdminId" value="">
                    <div style="padding:8px 12px;background:var(--gold-dim);border:1px solid var(--gold-ring);border-radius:7px;margin-bottom:14px;font-size:11.5px;color:var(--t2);" id="autoIdNotice">
                        <i class="fa fa-info-circle" style="color:var(--gold);margin-right:4px;"></i>
                        Admin ID (ADM0001, ADM0002...) will be auto-generated.
                    </div>
                    <div class="row">
                        <div class="col-sm-6"><div class="au-fg"><label>Full Name *</label><input type="text" id="aName" maxlength="60" required></div></div>
                        <div class="col-sm-6"><div class="au-fg"><label>Email *</label><input type="email" id="aEmail" maxlength="80" required></div></div>
                    </div>
                    <div class="row">
                        <div class="col-sm-6"><div class="au-fg"><label>Phone</label><input type="text" id="aPhone" maxlength="20"></div></div>
                        <div class="col-sm-6"><div class="au-fg"><label>Role *</label><select id="aRole"><option value="">Select role...</option></select></div></div>
                    </div>
                    <div class="row" id="passwordRow">
                        <div class="col-sm-6"><div class="au-fg"><label>Password *</label><input type="password" id="aPassword" minlength="8"></div></div>
                        <div class="col-sm-6" style="display:flex;align-items:flex-end;padding-bottom:12px;">
                            <span style="font-size:11px;color:var(--t3);">Min 8 characters. Stored as bcrypt hash.</span>
                        </div>
                    </div>
                </div>
                <div class="modal-footer" style="border-color:var(--border);">
                    <button type="button" class="au-btn au-btn-s" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="au-btn au-btn-p" id="saveAdminBtn"><i class="fa fa-check"></i> Save</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════ RESET PASSWORD MODAL ═══ -->
<div class="modal fade" id="resetPwModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content" style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;">
            <div class="modal-header" style="border-color:var(--border);">
                <button type="button" class="close" data-dismiss="modal" style="color:var(--t3);">&times;</button>
                <h4 class="modal-title" style="font-family:var(--font-b);color:var(--t1);">
                    <i class="fa fa-key" style="color:var(--amber);margin-right:8px;"></i>Reset Password
                </h4>
            </div>
            <form id="resetPwForm" autocomplete="off">
                <div class="modal-body">
                    <input type="hidden" id="resetPwAdminId" value="">
                    <p style="font-size:12.5px;color:var(--t2);margin-bottom:12px;">
                        Reset password for <strong id="resetPwName"></strong>
                    </p>
                    <div class="au-fg"><label>New Password *</label><input type="password" id="resetPwInput" minlength="8" required></div>
                </div>
                <div class="modal-footer" style="border-color:var(--border);">
                    <button type="button" class="au-btn au-btn-s" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="au-btn au-btn-p"><i class="fa fa-key"></i> Reset</button>
                </div>
            </form>
        </div>
    </div>
</div>
</div>

<!-- ═══════════════════════════════════ CREDENTIALS SUCCESS MODAL ═══ -->
<div class="modal fade" id="credentialsModal" tabindex="-1" data-backdrop="static">
    <div class="modal-dialog modal-sm">
        <div class="modal-content" style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;">
            <div class="modal-header" style="border-color:var(--border);background:rgba(16,185,129,.08);">
                <h4 class="modal-title" style="font-family:var(--font-b);color:var(--t1);font-size:14px;">
                    <i class="fa fa-check-circle" style="color:#10b981;margin-right:8px;"></i>Admin Created Successfully
                </h4>
            </div>
            <div class="modal-body" style="padding:20px;">
                <div style="background:var(--bg);border:1px solid var(--border);border-radius:8px;padding:14px;margin-bottom:12px;">
                    <div style="margin-bottom:10px;">
                        <span style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;">Admin ID</span>
                        <div style="font-size:16px;font-weight:700;color:var(--gold);font-family:var(--font-m);" id="credAdminId"></div>
                    </div>
                    <div style="margin-bottom:10px;">
                        <span style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;">Name</span>
                        <div style="font-size:13px;color:var(--t1);font-weight:600;" id="credName"></div>
                    </div>
                    <div style="margin-bottom:10px;">
                        <span style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;">Role</span>
                        <div style="font-size:13px;color:var(--t1);" id="credRole"></div>
                    </div>
                    <div>
                        <span style="font-size:11px;color:var(--t3);text-transform:uppercase;letter-spacing:.5px;">Password</span>
                        <div style="font-size:14px;font-weight:700;color:#dc2626;font-family:var(--font-m);letter-spacing:.5px;" id="credPassword"></div>
                    </div>
                </div>
                <p style="font-size:11.5px;color:var(--t3);margin:0;line-height:1.5;">
                    <i class="fa fa-exclamation-triangle" style="color:#f59e0b;margin-right:4px;"></i>
                    Share these credentials securely. The password cannot be retrieved later.
                </p>
            </div>
            <div class="modal-footer" style="border-color:var(--border);">
                <button type="button" class="au-btn au-btn-p" data-dismiss="modal"><i class="fa fa-check"></i> Got it</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
'use strict';
var B = typeof BASE_URL !== 'undefined' ? BASE_URL : <?= json_encode(base_url()) ?>;
var CURRENT_ADMIN = <?= json_encode($admin_id ?? '') ?>;
var CURRENT_ROLE  = <?= json_encode($admin_role ?? '') ?>;
var isAdmin = (CURRENT_ROLE === 'Super Admin' || CURRENT_ROLE === 'School Super Admin' || CURRENT_ROLE === 'Admin') || <?= json_encode(has_permission('Admin Users')) ?>;
var MODULES = <?= json_encode($available_modules ?? []) ?>;

function esc(s){ return $('<div>').text(s||'').html(); }
function ts(s){ return (s||'').substring(0,19).replace('T',' '); }
function csrf(extra){ var o={}; if(typeof csrfName!=='undefined') o[csrfName]=csrfToken; return $.extend(o,extra||{}); }
function toast(msg,ok){var t=$('<div style="position:fixed;top:20px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:.85rem;color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.15);background:'+(ok?'var(--gold)':'#dc2626')+'">'+esc(msg)+'</div>');$('body').append(t);setTimeout(function(){t.fadeOut(300,function(){t.remove()})},3000);}

/* ── Tab switching ─────────────────────────────────────────────── */
$('.au-tab').on('click', function(){
    $('.au-tab').removeClass('active');
    $(this).addClass('active');
    var t = $(this).attr('data-tab');
    $('.au-pane').removeClass('active');
    $('#pane-'+t).addClass('active');
    if(t==='dashboard') loadDashboard();
    if(t==='admins')    loadAdmins();
    if(t==='roles')     loadRoles();
    if(t==='logs')      loadLogs();
});

/* ══════════════════════════════════ DASHBOARD ══════════════════ */
function loadDashboard(){
    $.post(B+'admin_users/get_dashboard',csrf(),function(r){
        if(r.status!=='success') return;
        $('#kpi-total').text(r.total);
        $('#kpi-active').text(r.active);
        $('#kpi-disabled').text(r.disabled);
        if(!r.recent||!r.recent.length){
            $('#dashRecentBody').html('<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--t3);">No login activity yet.</td></tr>');
            return;
        }
        var h = r.recent.map(function(l){
            var st = (l.status||'success')==='success' ? '<span class="au-badge au-b-green">Success</span>' : '<span class="au-badge au-b-red">Failed</span>';
            return '<tr><td style="font-size:11.5px;font-family:var(--font-m);color:var(--t3);">'+esc(ts(l.loginTime))+'</td>'
                +'<td>'+esc(l.adminName||l.adminId||'')+'<div style="font-size:10px;color:var(--t3);">'+esc(l.adminId||'')+'</div></td>'
                +'<td style="font-size:11.5px;font-family:var(--font-m);">'+esc(l.ipAddress||'')+'</td>'
                +'<td style="font-size:11.5px;color:var(--t3);">'+esc(l.device||'-')+'</td>'
                +'<td>'+st+'</td></tr>';
        }).join('');
        $('#dashRecentBody').html(h);
    },'json');
}

/* ══════════════════════════════════ ADMIN USERS ════════════════ */
var _admins = [];
var _roles  = [];

function loadAdmins(){
    $.post(B+'admin_users/get_admins',csrf(),function(r){
        if(r.status!=='success'){ toast(r.message||'Failed to load admins',false); return; }
        _admins = r.admins||[];
        renderAdmins();
    },'json').fail(function(){ toast('Network error loading admins.',false); });
}

function renderAdmins(){
    var q = ($('#adminSearch').val()||'').toLowerCase();
    var rows = _admins.filter(function(a){
        if(!q) return true;
        return (a.name||'').toLowerCase().indexOf(q)>=0 || (a.email||'').toLowerCase().indexOf(q)>=0 || (a.role||'').toLowerCase().indexOf(q)>=0;
    });
    if(!rows.length){
        $('#adminsTbody').html('<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--t3);">No admin users found.</td></tr>');
        return;
    }
    var h = rows.map(function(a){
        var st = (a.status||'active')==='active' ? '<span class="au-badge au-b-green">Active</span>' : '<span class="au-badge au-b-red">Disabled</span>';
        var isSelf = a.adminId === CURRENT_ADMIN;
        var aid = esc(a.adminId);
        var aname = esc(a.name);
        var acts = '<div style="display:flex;gap:4px;flex-wrap:wrap;">';
        acts += '<button class="au-btn au-btn-sm au-btn-s act-edit" data-id="'+aid+'"><i class="fa fa-pencil"></i></button>';
        if(isAdmin){
            acts += '<button class="au-btn au-btn-sm au-btn-s act-resetpw" data-id="'+aid+'" data-name="'+aname+'" title="Reset Password"><i class="fa fa-key"></i></button>';
            if(!isSelf){
                var toggleSt = (a.status||'active')==='active' ? 'disabled' : 'active';
                var toggleIc = toggleSt==='disabled' ? 'fa-ban' : 'fa-check';
                acts += '<button class="au-btn au-btn-sm au-btn-s act-toggle" data-id="'+aid+'" data-status="'+toggleSt+'" title="'+(toggleSt==='disabled'?'Disable':'Enable')+'"><i class="fa '+toggleIc+'"></i></button>';
                acts += '<button class="au-btn au-btn-sm au-btn-d act-delete" data-id="'+aid+'" data-name="'+aname+'" title="Delete"><i class="fa fa-trash"></i></button>';
            }
        }
        acts += '</div>';
        return '<tr>'
            +'<td><strong>'+esc(a.name)+'</strong>'+(isSelf?' <span class="au-badge au-b-blue" style="font-size:9px;">You</span>':'')+'</td>'
            +'<td style="font-size:12px;">'+esc(a.email)+'</td>'
            +'<td style="font-size:12px;">'+esc(a.phone||'-')+'</td>'
            +'<td><span class="au-badge au-b-blue">'+esc(a.role)+'</span></td>'
            +'<td>'+st+'</td>'
            +'<td style="font-size:11px;color:var(--t3);">'+esc((a.createdAt||'').substring(0,10))+'</td>'
            +'<td style="font-size:11px;color:var(--t3);">'+esc(ts(a.lastLogin)||'Never')+'</td>'
            +'<td>'+acts+'</td></tr>';
    }).join('');
    $('#adminsTbody').html(h);
}

$('#adminSearch').on('input', renderAdmins);

// Add Admin
$('#addAdminBtn').on('click', function(){
    if(!isAdmin){ alert('Only Admin role can create administrators.'); return; }
    $('#editAdminId').val('');
    $('#adminForm')[0].reset();
    $('#autoIdNotice').show();
    $('#passwordRow').show();
    $('#adminModalTitle').html('<i class="fa fa-user-plus" style="color:var(--gold);margin-right:8px;"></i>Add Admin');
    loadRoleOptions();
    $('#adminModal').modal('show');
});

function loadRoleOptions(selected){
    $.post(B+'admin_users/get_roles',csrf(),function(r){
        if(r.status!=='success') return;
        _roles = r.roles||[];
        var opts = '<option value="">Select role...</option>';
        var tierLabels = {1:'Full Access',2:'Leadership',3:'Department Heads',4:'Operational Staff',5:'Specialist Roles',6:'Minimal Access',7:'Custom Roles'};
        var lastTier = 0;
        _roles.forEach(function(rl){
            var tier = rl.tier || (rl.is_system ? 6 : 7);
            if(tier !== lastTier){
                if(lastTier !== 0) opts += '</optgroup>';
                lastTier = tier;
                opts += '<optgroup label="'+esc(tierLabels[tier]||'Other')+'">';
            }
            opts += '<option value="'+esc(rl.role_name)+'"'+(rl.role_name===selected?' selected':'')+'>'+esc(rl.label||rl.role_name)+'</option>';
        });
        if(lastTier !== 0) opts += '</optgroup>';
        $('#aRole').html(opts);
    },'json');
}

// Edit Admin (delegated)
$(document).on('click', '.act-edit', function(){
    var id = String($(this).attr('data-id')||'');
    var a = _admins.find(function(x){ return x.adminId===id; });
    if(!a) return;
    $('#editAdminId').val(id);
    $('#aName').val(a.name); $('#aEmail').val(a.email); $('#aPhone').val(a.phone||'');
    $('#autoIdNotice').hide();
    $('#passwordRow').hide();
    $('#adminModalTitle').html('<i class="fa fa-pencil" style="color:var(--gold);margin-right:8px;"></i>Edit Admin');
    loadRoleOptions(a.role);
    $('#adminModal').modal('show');
});

// Save Admin (create or update)
$('#adminForm').on('submit', function(e){
    e.preventDefault();
    var id = $('#editAdminId').val();
    var isEdit = !!id;
    var url = isEdit ? B+'admin_users/update_admin' : B+'admin_users/create_admin';
    var payload = { name:$('#aName').val(), email:$('#aEmail').val(), phone:$('#aPhone').val(), role:$('#aRole').val() };
    if(isEdit) payload.admin_id = id;
    else { payload.password = $('#aPassword').val(); }

    var $btn = $('#saveAdminBtn').prop('disabled',true);
    $.post(url, csrf(payload), function(r){
        $btn.prop('disabled',false);
        if(r.status==='success'){
            $('#adminModal').modal('hide');
            if(!isEdit && r.admin_id){
                // Show credentials dialog for newly created admin
                $('#credAdminId').text(r.admin_id);
                $('#credName').text(r.name||payload.name);
                $('#credRole').text(r.role||payload.role);
                $('#credPassword').text(r.password||'********');
                $('#credentialsModal').modal('show');
            } else {
                toast(r.message,true);
            }
            loadAdmins();
            loadDashboard();
        } else {
            toast(r.message,false);
        }
    },'json').fail(function(){ $btn.prop('disabled',false); });
});

// Toggle status (delegated)
$(document).on('click', '.act-toggle', function(){
    var id = String($(this).attr('data-id')||'');
    var status = $(this).attr('data-status');
    if(!confirm((status==='disabled'?'Disable':'Enable')+' this admin?')) return;
    $.post(B+'admin_users/disable_admin',csrf({admin_id:id, status:status}),function(r){
        if(r.status==='success'){ loadAdmins(); loadDashboard(); toast(r.message,true); }
        else { toast(r.message,false); }
    },'json').fail(function(){ toast('Network error.',false); });
});

// Delete (delegated)
$(document).on('click', '.act-delete', function(){
    var id = String($(this).attr('data-id')||'');
    var name = $(this).attr('data-name');
    if(!confirm('Permanently delete admin "'+name+'"? This cannot be undone.')) return;
    $.post(B+'admin_users/delete_admin',csrf({admin_id:id}),function(r){
        if(r.status==='success'){ loadAdmins(); loadDashboard(); toast(r.message,true); }
        else { toast(r.message,false); }
    },'json').fail(function(){ toast('Network error.',false); });
});

// Reset password (delegated)
$(document).on('click', '.act-resetpw', function(){
    var id = String($(this).attr('data-id')||'');
    var name = $(this).attr('data-name');
    $('#resetPwAdminId').val(id);
    $('#resetPwName').text(name);
    $('#resetPwInput').val('');
    $('#resetPwModal').modal('show');
});
$('#resetPwForm').on('submit', function(e){
    e.preventDefault();
    var pw = $('#resetPwInput').val();
    if(pw.length<8){ alert('Password must be at least 8 characters.'); return; }
    $.post(B+'admin_users/reset_password',csrf({admin_id:$('#resetPwAdminId').val(), new_password:pw}),function(r){
        if(r.status==='success'){ $('#resetPwModal').modal('hide'); toast(r.message,true); }
        else { toast(r.message,false); }
    },'json').fail(function(){ toast('Network error.',false); });
});

/* ══════════════════════════════════ ROLES & PERMISSIONS ════════ */
function loadRoles(){
    $.post(B+'admin_users/get_roles',csrf(),function(r){
        if(r.status!=='success') return;
        _roles = r.roles||[];
        renderRolesList();
    },'json');
}

function renderRolesList(){
    if(!_roles.length){
        $('#rolesListWrap').html('<p style="text-align:center;color:var(--t3);padding:20px;">No roles defined.</p>');
        return;
    }
    var tierLabels = {1:'Full Access',2:'Leadership',3:'Department Heads',4:'Operational Staff',5:'Specialist Roles',6:'Minimal Access',7:'Custom Roles'};
    var tierColors = {1:'#dc2626',2:'#7c3aed',3:'#2563eb',4:'#16a34a',5:'#d97706',6:'#6b7280',7:'#64748b'};
    var lastTier = 0;
    var h = _roles.map(function(rl){
        var tierHeader = '';
        var tier = rl.tier || (rl.is_system ? 6 : 7);
        if(tier !== lastTier){
            lastTier = tier;
            var tl = tierLabels[tier] || 'Other';
            var tc = tierColors[tier] || '#6b7280';
            tierHeader = '<div style="padding:6px 14px;background:var(--bg3);border-bottom:1px solid var(--border);'
                +'font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.6px;font-family:var(--font-m);'
                +'color:'+tc+';display:flex;align-items:center;gap:6px;">'
                +'<span style="width:8px;height:8px;border-radius:50%;background:'+tc+';display:inline-block;"></span>'+esc(tl)+'</div>';
        }
        var sys = rl.is_system ? '<span class="au-badge au-b-gray" style="font-size:9px;margin-left:6px;">System</span>' : '';
        var perms = (rl.permissions||[]).length;
        var delBtn = (!rl.is_system && isAdmin) ? ' <button class="au-btn au-btn-sm au-btn-d del-role-btn" data-role="'+esc(rl.role_name)+'" data-label="'+esc(rl.label||rl.role_name)+'" title="Delete role" style="float:right;margin-top:-2px;"><i class="fa fa-trash"></i></button>' : '';
        return tierHeader + '<div style="padding:12px 14px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s;" '
            +'class="role-item" data-role="'+esc(rl.role_name)+'" '
            +'onmouseover="this.style.background=\'var(--gold-dim)\'" onmouseout="this.style.background=\'\'">'
            +'<div style="display:flex;align-items:center;gap:8px;">'
            +'<strong style="color:var(--t1);font-size:13px;">'+esc(rl.label||rl.role_name)+'</strong>'+sys+delBtn
            +'</div>'
            +'<div style="font-size:11px;color:var(--t3);margin-top:2px;">'+esc(rl.description||'')+'</div>'
            +'<div style="font-size:10.5px;color:var(--gold);font-family:var(--font-m);margin-top:3px;">'+perms+' module(s)</div>'
            +'</div>';
    }).join('');
    $('#rolesListWrap').html(h);
}

$(document).on('click', '.role-item', function(e){
    if($(e.target).closest('.del-role-btn').length) return;
    var name = $(this).attr('data-role');
    var rl = _roles.find(function(r){ return r.role_name===name; });
    if(!rl) return;
    showRoleEditor(rl, false);
});

// Delete custom role
$(document).on('click', '.del-role-btn', function(e){
    e.stopPropagation();
    var name = $(this).attr('data-role');
    var label = $(this).attr('data-label');
    if(!confirm('Delete role "'+label+'"? This cannot be undone.')) return;
    $.post(B+'admin_users/delete_role',csrf({role_name:name}),function(r){
        if(r.status==='success'){ toast(r.message,true); loadRoles(); $('#roleEditorBox').hide(); $('#roleEditorPlaceholder').show(); }
        else { toast(r.message,false); }
    },'json').fail(function(){ toast('Network error deleting role.',false); });
});

$('#addRoleBtn').on('click', function(){
    if(!isAdmin){ alert('Only Admin role can create roles.'); return; }
    showRoleEditor(null, true);
});

function showRoleEditor(role, isNew){
    $('#roleEditorPlaceholder').hide();
    $('#roleEditorBox').show();
    $('#roleEditorTitle').text(isNew ? 'Create Role' : 'Edit Role');

    var name  = isNew ? '' : (role.role_name||'');
    var label = isNew ? '' : (role.label||'');
    var desc  = isNew ? '' : (role.description||'');
    var perms = isNew ? [] : (role.permissions||[]);
    var sys   = !isNew && role.is_system;

    $('#roleOrigName').val(name);
    $('#roleNameInput').val(name).prop('disabled', sys || !isNew);
    $('#roleLabelInput').val(label);
    $('#roleDescInput').val(desc);

    // Build permission chips
    var ph = MODULES.map(function(m){
        var sel = perms.indexOf(m) >= 0 ? ' selected' : '';
        return '<div class="au-perm-chip'+sel+'" data-mod="'+esc(m)+'">'+esc(m)+'</div>';
    }).join('');
    $('#permGrid').html(ph);
}

$(document).on('click', '.au-perm-chip', function(e){
    if(!isAdmin) return;
    $(this).toggleClass('selected');
});


$('#cancelRoleBtn').on('click', function(){
    $('#roleEditorBox').hide();
    $('#roleEditorPlaceholder').show();
});

$('#roleForm').on('submit', function(e){
    e.preventDefault();
    if(!isAdmin){ alert('Only Admin role can modify roles.'); return; }
    var name = $('#roleOrigName').val() || $('#roleNameInput').val();
    var perms = [];
    $('#permGrid .au-perm-chip.selected').each(function(){ perms.push($(this).attr('data-mod')); });

    $.post(B+'admin_users/save_role',csrf({
        role_name: name,
        label: $('#roleLabelInput').val(),
        description: $('#roleDescInput').val(),
        'permissions[]': perms.length ? perms : ['_none_']
    }),function(r){
        if(r.status==='success'){
            toast(r.message,true);
            loadRoles();
        } else {
            toast(r.message,false);
        }
    },'json');
});

/* ══════════════════════════════════ LOGIN ACTIVITY ═════════════ */
var _logs = [];
var _logFilter = 'all';

function loadLogs(){
    $.post(B+'admin_users/get_login_logs',csrf(),function(r){
        if(r.status!=='success') return;
        _logs = r.logs||[];
        renderLogs();
        $('#logsMeta').text(r.total+' total entries'+(r.total>200?' (showing 200)':''));
    },'json');
}

function renderLogs(){
    var q = ($('#logSearch').val()||'').toLowerCase();
    var rows = _logs.filter(function(l){
        if(_logFilter!=='all' && (l.status||'success')!==_logFilter) return false;
        if(q && (l.adminId||'').toLowerCase().indexOf(q)<0 && (l.adminName||'').toLowerCase().indexOf(q)<0 && (l.ipAddress||'').toLowerCase().indexOf(q)<0) return false;
        return true;
    });
    if(!rows.length){
        $('#logsTbody').html('<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--t3);">No matching entries.</td></tr>');
        return;
    }
    var h = rows.map(function(l){
        var st = (l.status||'success')==='success' ? '<span class="au-badge au-b-green">Success</span>' : '<span class="au-badge au-b-red">Failed</span>';
        var online = l.isOnline ? ' <span class="au-badge au-b-green" style="font-size:9px;">Online</span>' : '';
        return '<tr>'
            +'<td style="font-size:11.5px;font-family:var(--font-m);color:var(--t3);">'+esc(ts(l.loginTime))+'</td>'
            +'<td>'+esc(l.adminName||l.adminId||'')+online+'<div style="font-size:10px;color:var(--t3);">'+esc(l.adminId||'')+'</div></td>'
            +'<td style="font-size:11.5px;font-family:var(--font-m);">'+esc(l.ipAddress||'')+'</td>'
            +'<td style="font-size:11.5px;color:var(--t3);">'+esc(l.device||'-')+'</td>'
            +'<td>'+st+'</td></tr>';
    }).join('');
    $('#logsTbody').html(h);
}

$('#logSearch').on('input', renderLogs);
$(document).on('click', '.log-filter', function(){
    $('.log-filter').removeClass('active');
    $(this).addClass('active');
    _logFilter = $(this).attr('data-filter');
    renderLogs();
});

/* ── Init ──────────────────────────────────────────────────────── */
loadDashboard();

});
</script>
