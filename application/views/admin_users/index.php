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
.au-wrap{max-height:500px;overflow-y:auto;overflow-x:auto;border:1px solid var(--border);border-radius:8px;}
.au-table{min-width:640px;}
/* Keep tall modals inside short/landscape viewports instead of clipping. */
#adminModal .modal-body,#resetPwModal .modal-body,#newRoleModal .modal-body{max-height:72vh;overflow-y:auto;}

/* Badges */
.au-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10.5px;font-weight:600;letter-spacing:.3px;}
.au-b-green{background:rgba(22,163,74,.12);color:#16a34a;}
.au-b-red{background:rgba(220,38,38,.10);color:#dc2626;}
.au-b-blue{background:rgba(59,130,246,.12);color:#3b82f6;}
.au-b-amber{background:rgba(217,119,6,.12);color:#d97706;}
.au-b-gray{background:rgba(107,114,128,.12);color:#6b7280;}

/* Permission picker — grouped chips with a checkbox affordance + fill.
   Selected reads instantly (solid brand pill + white check + white text). */
.au-cat{margin-top:16px;}
.au-cat:first-child{margin-top:6px;}
.au-cat-h{font:700 10.5px var(--font-m);letter-spacing:.13em;color:var(--t3);text-transform:uppercase;
    margin:0 0 9px;display:flex;align-items:center;gap:8px;opacity:.85;}
.au-cat-h::after{content:"";flex:1;height:1px;background:var(--border);}
.au-perm-grid{display:flex;flex-wrap:wrap;gap:8px;align-items:center;}
.au-perm-chip{display:inline-flex;align-items:center;gap:8px;padding:8px 13px 8px 10px;border-radius:9px;
    font:650 12.5px var(--font-b);color:var(--t3);background:var(--bg3);border:1.5px solid var(--border);
    cursor:pointer;transition:all .16s var(--ease);user-select:none;-webkit-tap-highlight-color:transparent;}
.au-perm-chip .box{width:16px;height:16px;border-radius:5px;border:1.5px solid var(--t3);flex:none;
    display:grid;place-items:center;color:transparent;transition:all .16s;}
.au-perm-chip .box svg{width:11px;height:11px;}
.au-perm-chip:hover{border-color:var(--gold);color:var(--t1);background:var(--gold-dim);}
.au-perm-chip:hover .box{border-color:var(--gold);}
.au-perm-chip:focus-visible{outline:none;box-shadow:0 0 0 3px var(--gold-ring);}
.au-perm-chip.selected{background:var(--gold);border-color:var(--gold);color:#fff;
    box-shadow:0 3px 10px -2px var(--gold-ring);font-weight:700;}
.au-perm-chip.selected .box{background:#fff;border-color:#fff;color:var(--gold);}
/* Operations sub-modules — nested tray with a rail. */
.au-parent-wrap{width:100%;}
.au-subgroup{margin:9px 0 4px 6px;padding:12px 12px 12px 16px;border-left:2px solid var(--gold-ring);
    background:var(--bg3);border-radius:0 10px 10px 0;}
.au-subgroup .sg-h{font:600 11px var(--font-b);color:var(--t3);margin:-2px 0 9px;}
.au-perm-chip.sub{font-size:12px;padding:7px 12px 7px 9px;}
/* "included" = granted via parent umbrella: same solid fill + white text, but locked. */
.au-perm-chip.included{cursor:not-allowed;opacity:.82;}
.au-perm-chip.included .lock{font-size:10px;margin-left:1px;}
.au-perm-picker{display:block;}
#newRolePermGrid{max-height:46vh;overflow-y:auto;padding-right:4px;}
/* New-role modal */
.au-refline{font-size:12px;color:var(--t3);margin-top:7px;}
.au-refline code{font:600 12px var(--font-m);color:var(--gold);background:var(--gold-dim);padding:2px 7px;border-radius:5px;}

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
        <div class="au-tab" data-tab="access"><i class="fa fa-key" style="margin-right:5px;"></i>Access</div>
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
            <div class="au-box-head"><i class="fa fa-history" style="color:var(--gold);"></i> Recent Logins</div>
            <div class="au-wrap" style="max-height:320px;">
                <table class="au-table">
                    <thead><tr><th>Time</th><th>Admin</th><th>IP Address</th></tr></thead>
                    <tbody id="dashRecentBody">
                        <tr><td colspan="3" style="text-align:center;padding:24px;color:var(--t3);"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════ ADMIN USERS ═══ -->
    <div class="au-pane" id="pane-admins">

        <div style="display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;">
            <input type="text" id="adminSearch" placeholder="Search by ID, name, phone, or role..." class="form-control"
                aria-label="Search admins by ID, name, phone, email, or role"
                style="width:260px;font-size:12.5px;height:34px;">
            <?php if (in_array($admin_role, ['Super Admin', 'School Super Admin', 'Admin'])): ?>
            <div style="margin-left:auto;">
                <button class="au-btn au-btn-p" id="addAdminBtn"><i class="fa fa-plus"></i> Add Admin</button>
            </div>
            <?php endif; ?>
        </div>

        <div class="au-wrap">
            <table class="au-table" id="adminsTable">
                <thead><tr>
                    <th style="width:110px;">Admin ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Role</th>
                    <th>Status</th><th>Created</th><th>Last Login</th><th style="width:140px;">Actions</th>
                </tr></thead>
                <tbody id="adminsTbody">
                    <tr><td colspan="9" style="text-align:center;padding:24px;color:var(--t3);"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════ ACCESS ═══ -->
    <!-- Access = the module-permission facet of a role (what it can open in the
         panel). Roles themselves (name/category/departments) are defined in
         Departments & Roles; here you control each role's panel access. -->
    <div class="au-pane" id="pane-access">

        <div style="padding:10px 14px;background:var(--gold-dim);border:1px solid var(--gold-ring);border-radius:8px;margin-bottom:16px;font-size:12px;color:var(--t2);line-height:1.5;">
            <i class="fa fa-info-circle" style="color:var(--gold);margin-right:4px;"></i>
            <strong>Access</strong> decides which sections of the admin panel a role can open. A role is a person's job (defined in
            <a href="<?= base_url('org') ?>" style="color:var(--gold);font-weight:600;">Departments &amp; Roles</a>); its Access is set here.
            Anyone who only logs into the mobile apps isn't affected.
        </div>

        <div class="row">
            <div class="col-md-5">
                <div class="au-box">
                    <div class="au-box-head"><i class="fa fa-users" style="color:var(--gold);"></i> Roles
                        <?php if (in_array($admin_role, ['Super Admin', 'School Super Admin', 'Admin'])): ?>
                        <button type="button" class="au-btn au-btn-p" id="newRoleBtn" style="margin-left:auto;padding:5px 11px;font-size:12px;"><i class="fa fa-plus"></i> New role</button>
                        <?php endif; ?>
                    </div>
                    <input type="text" id="accessSearch" placeholder="Search roles…" class="form-control"
                        aria-label="Search roles" style="width:100%;font-size:12.5px;height:32px;margin-bottom:10px;">
                    <div id="accessListWrap" style="max-height:440px;overflow-y:auto;">
                        <p style="text-align:center;color:var(--t3);padding:20px;"><i class="fa fa-spinner fa-spin"></i></p>
                    </div>
                </div>
            </div>
            <div class="col-md-7">
                <div class="au-box" id="accessEditorBox" style="display:none;">
                    <div class="au-box-head">
                        <i class="fa fa-key" style="color:var(--gold);"></i>
                        Access for <span id="accessRoleName" style="margin-left:4px;">—</span>
                        <span id="accessCount" class="au-badge au-b-blue" style="margin-left:auto;">0</span>
                    </div>
                    <p style="font-size:11.5px;color:var(--t3);margin:-4px 0 12px;">Tick the panel sections this role can open.</p>
                    <input type="hidden" id="accessRoleId" value="">
                    <div class="au-perm-picker" id="accessPermGrid"></div>
                    <div style="display:flex;gap:8px;margin-top:16px;align-items:center;">
                        <button type="button" class="au-btn au-btn-p" id="saveAccessBtn"><i class="fa fa-check"></i> Save Access</button>
                        <button type="button" class="au-btn au-btn-s" id="deleteRoleBtn" style="display:none;margin-left:auto;color:#dc2626;border-color:rgba(220,38,38,.3);"><i class="fa fa-trash"></i> Delete role</button>
                    </div>
                </div>
                <div id="accessPlaceholder" style="text-align:center;color:var(--t3);padding:60px 20px;font-size:13px;">
                    <i class="fa fa-hand-pointer-o" style="font-size:24px;display:block;margin-bottom:10px;color:var(--gold);"></i>
                    Select a role on the left to set which panel sections it can open.
                </div>
            </div>
        </div>
    </div>

    <!-- ═══════════════════════════════════════════ LOGIN ACTIVITY ═══ (placeholder cleared) -->

    <!-- ═══════════════════════════════════════════ LOGIN ACTIVITY ═══ -->
    <div class="au-pane" id="pane-logs">

        <div style="display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap;">
            <div style="display:flex;gap:5px;">
                <button class="au-btn au-btn-sm au-btn-s log-filter active" data-filter="all">All</button>
                <button class="au-btn au-btn-sm au-btn-s log-filter" data-filter="success">Success</button>
                <button class="au-btn au-btn-sm au-btn-s log-filter" data-filter="failed" style="color:#dc2626;">Failed</button>
                <button class="au-btn au-btn-sm au-btn-s log-filter" data-filter="locked" style="color:#d97706;">Locked</button>
            </div>
            <input type="text" id="logSearch" placeholder="Filter admin, IP..." class="form-control"
                aria-label="Filter logins by admin or IP"
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
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color:var(--t3);">&times;</button>
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
                        <div class="col-sm-6"><div class="au-fg"><label>Role *</label><select id="aRole" required><option value="">Select role...</option></select></div></div>
                    </div>
                    <div class="row" id="passwordRow">
                        <div class="col-sm-6"><div class="au-fg"><label>Password *</label>
                            <div style="position:relative;">
                                <input type="password" id="aPassword" minlength="8" style="width:100%;padding-right:36px;">
                                <button type="button" id="aPwToggle" aria-label="Show password"
                                    style="position:absolute;right:8px;top:50%;transform:translateY(-50%);background:none;border:none;color:var(--t3);cursor:pointer;padding:0;font-size:13px;line-height:1;"><i class="fa fa-eye"></i></button>
                            </div>
                        </div></div>
                        <div class="col-sm-6" style="display:flex;align-items:flex-end;padding-bottom:12px;">
                            <span style="font-size:11px;color:var(--t3);">8–72 chars, incl. one uppercase, one lowercase, and a digit.</span>
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
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color:var(--t3);">&times;</button>
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
                    <div class="au-fg"><label>New Password *</label><input type="password" id="resetPwInput" minlength="8" required>
                        <span style="font-size:11px;color:var(--t3);margin-top:3px;">8–72 chars, one upper, one lower, one digit.</span>
                    </div>
                </div>
                <div class="modal-footer" style="border-color:var(--border);">
                    <button type="button" class="au-btn au-btn-s" data-dismiss="modal">Cancel</button>
                    <button type="submit" class="au-btn au-btn-p"><i class="fa fa-key"></i> Reset</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════ NEW ROLE MODAL ═══ -->
<div class="modal fade" id="newRoleModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content" style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;">
            <div class="modal-header" style="border-color:var(--border);">
                <button type="button" class="close" data-dismiss="modal" aria-label="Close" style="color:var(--t3);">&times;</button>
                <h4 class="modal-title" style="font-family:var(--font-b);color:var(--t1);">
                    <i class="fa fa-plus-circle" style="color:var(--gold);margin-right:8px;"></i>Create Role
                </h4>
            </div>
            <div class="modal-body" style="padding:20px;">
                <div class="row">
                    <div class="col-md-7 au-fg" style="margin-bottom:14px;">
                        <label>Role name *</label>
                        <input type="text" id="newRoleName" maxlength="40" placeholder="e.g. Exam Controller" autocomplete="off">
                        <div class="au-refline">Reference ID preview <code id="newRoleRef">—</code> · the final ID is generated on save and can’t change later.</div>
                    </div>
                    <div class="col-md-5 au-fg" style="margin-bottom:14px;">
                        <label>Category *</label>
                        <select id="newRoleCategory">
                            <option>Teaching</option><option>Non-Teaching</option>
                            <option selected>Administrative</option><option>Support</option>
                        </select>
                    </div>
                </div>
                <p style="font-size:11.5px;color:var(--t3);margin:4px 0 10px;">Choose which panel sections this role can open:</p>
                <div class="au-perm-picker" id="newRolePermGrid"></div>
            </div>
            <div class="modal-footer" style="border-color:var(--border);">
                <button type="button" class="au-btn au-btn-s" data-dismiss="modal">Cancel</button>
                <button type="button" class="au-btn au-btn-p" id="createRoleBtn"><i class="fa fa-check"></i> Create role</button>
            </div>
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
                <p style="font-size:11.5px;color:var(--t2);margin:0 0 8px;line-height:1.5;">
                    <i class="fa fa-sign-in" style="color:var(--gold);margin-right:4px;"></i>
                    They sign in with their <strong>Admin ID</strong> (<span id="credLoginId" style="font-family:var(--font-m);"></span>) and this password — not the email address.
                </p>
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
// Edit capability MUST match the server: AdminUsers::_require_role passes only
// these three roles. Deriving this from has_permission('Admin Users') too made
// the UI render edit/reset/delete buttons that the backend then 403'd (M9).
var isAdmin = (CURRENT_ROLE === 'Super Admin' || CURRENT_ROLE === 'School Super Admin' || CURRENT_ROLE === 'Admin');
var MODULES = <?= json_encode($available_modules ?? []) ?>;
// Parent → children permission tree (e.g. Operations ⊇ Library/Transport/…).
// Granting the parent implies all children; or grant children individually.
var MODULE_CHILDREN = <?= json_encode(defined('RBAC_MODULE_CHILDREN') ? RBAC_MODULE_CHILDREN : new stdClass()) ?>;
var MODULE_PARENT = {};
Object.keys(MODULE_CHILDREN).forEach(function(p){ (MODULE_CHILDREN[p]||[]).forEach(function(c){ MODULE_PARENT[c]=p; }); });
// Domain grouping for the Access picker (presentation only). Any module not
// listed here falls into an "Other" group so the picker never drops a permission.
var MODULE_CATS = [
    {h:'Academics & Students', mods:['SIS','Academic','Attendance','Examinations','Results','LMS','Homework','Certificates']},
    {h:'Finance', mods:['Fees','Accounting']},
    {h:'Operations', mods:['Operations']},
    {h:'Communication & Engagement', mods:['Communication','Events','Stories']},
    {h:'People & Administration', mods:['HR','Admin Users','Reports','Configuration']}
];
var CHECK_SVG = '<svg viewBox="0 0 12 12" fill="none"><path d="M2.5 6.2l2.3 2.3 4.7-5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>';

// Escapes for BOTH text and attribute contexts. The base .text().html() covers
// < > &; the two replaces close the attribute-injection hole (data-*/title/…),
// e.g. an admin name or a login User-Agent containing a double-quote (C1).
function esc(s){ return $('<div>').text(s||'').html().replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function ts(s){ return (s||'').substring(0,19).replace('T',' '); }
// Single source for role-tier labels (was duplicated in two renderers — F14).
var TIER_LABELS = {1:'Full Access',2:'Leadership',3:'Department Heads',4:'Operational Staff',5:'Specialist Roles',6:'Minimal Access',7:'Custom Roles'};
// M6: shared error state — replaces a stuck spinner with a message + Retry, so
// no tab spins forever on a failed/errored load.
function tabError(sel, colspan, retry){
    var inner = 'Couldn’t load this. <a href="#" class="tab-retry" style="color:var(--gold);font-weight:600;">Retry</a>';
    var html = colspan
        ? '<tr><td colspan="'+colspan+'" style="text-align:center;padding:22px;color:#dc2626;">'+inner+'</td></tr>'
        : '<p style="text-align:center;padding:22px;color:#dc2626;">'+inner+'</p>';
    $(sel).html(html).find('.tab-retry').on('click', function(e){ e.preventDefault(); retry(); });
}
function csrf(extra){ var o={}; if(typeof csrfName!=='undefined') o[csrfName]=csrfToken; return $.extend(o,extra||{}); }
// Mirror of the server-side rule (create_admin / reset_password / change_my_password).
function pwError(pw){
    pw = pw || '';
    if(pw.length < 8 || pw.length > 72) return 'Password must be 8–72 characters.';
    if(!/[A-Z]/.test(pw) || !/[a-z]/.test(pw) || !/[0-9]/.test(pw))
        return 'Password must contain an uppercase letter, a lowercase letter, and a digit.';
    return '';
}
function toast(msg,ok){var t=$('<div style="position:fixed;top:20px;right:20px;z-index:99999;padding:12px 20px;border-radius:8px;font-size:.85rem;color:#fff;box-shadow:0 4px 12px rgba(0,0,0,.15);background:'+(ok?'var(--gold)':'#dc2626')+'">'+esc(msg)+'</div>');$('body').append(t);setTimeout(function(){t.fadeOut(300,function(){t.remove()})},3000);}

/* ── Tab switching (ARIA tablist + keyboard nav) ───────────────── */
var $tabs = $('.au-tab');
$('.au-tabs').attr('role','tablist');
$tabs.attr('role','tab').each(function(){
    var t = $(this).attr('data-tab');
    var active = $(this).hasClass('active');
    $(this).attr({tabindex: active?'0':'-1', 'aria-selected': active?'true':'false', id:'tab-'+t, 'aria-controls':'pane-'+t});
    $('#pane-'+t).attr({role:'tabpanel', 'aria-labelledby':'tab-'+t});
});

function activateTab($tab, focus){
    var t = $tab.attr('data-tab');
    $tabs.removeClass('active').attr({tabindex:'-1', 'aria-selected':'false'});
    $tab.addClass('active').attr({tabindex:'0', 'aria-selected':'true'});
    if(focus) $tab.focus();
    $('.au-pane').removeClass('active');
    $('#pane-'+t).addClass('active');
    if(t==='dashboard') loadDashboard();
    if(t==='admins')    loadAdmins();
    if(t==='access')    loadAccess();
    if(t==='logs')      loadLogs();
}

$tabs.on('click', function(){ activateTab($(this), false); });
$tabs.on('keydown', function(e){
    var idx = $tabs.index(this), n = $tabs.length;
    if(e.key==='ArrowRight'||e.key==='ArrowDown'){ e.preventDefault(); activateTab($tabs.eq((idx+1)%n), true); }
    else if(e.key==='ArrowLeft'||e.key==='ArrowUp'){ e.preventDefault(); activateTab($tabs.eq((idx-1+n)%n), true); }
    else if(e.key==='Home'){ e.preventDefault(); activateTab($tabs.first(), true); }
    else if(e.key==='End'){ e.preventDefault(); activateTab($tabs.last(), true); }
    else if(e.key==='Enter'||e.key===' '){ e.preventDefault(); activateTab($(this), false); }
});

/* ══════════════════════════════════ DASHBOARD ══════════════════ */
function loadDashboard(){
    $.post(B+'admin_users/get_dashboard',csrf(),function(r){
        if(r.status!=='success'){
            $('#kpi-total,#kpi-active,#kpi-disabled').text('—');
            tabError('#dashRecentBody', 3, loadDashboard);
            toast(r.message||'Failed to load dashboard.', false);
            return;
        }
        $('#kpi-total').text(r.total);
        $('#kpi-active').text(r.active);
        $('#kpi-disabled').text(r.disabled);
        if(!r.recent||!r.recent.length){
            $('#dashRecentBody').html('<tr><td colspan="3" style="text-align:center;padding:20px;color:var(--t3);">No login activity yet.</td></tr>');
            return;
        }
        var h = r.recent.map(function(l){
            var online = l.isOnline ? ' <span class="au-badge au-b-green" style="font-size:9px;">Online</span>' : '';
            return '<tr><td style="font-size:11.5px;font-family:var(--font-m);color:var(--t3);">'+esc(ts(l.loginTime))+'</td>'
                +'<td>'+esc(l.adminName||l.adminId||'')+online+'<div style="font-size:10px;color:var(--t3);">'+esc(l.adminId||'')+'</div></td>'
                +'<td style="font-size:11.5px;font-family:var(--font-m);">'+esc(l.ipAddress||'')+'</td></tr>';
        }).join('');
        $('#dashRecentBody').html(h);
    },'json').fail(function(){
        $('#kpi-total,#kpi-active,#kpi-disabled').text('—');
        tabError('#dashRecentBody', 3, loadDashboard);
        toast('Network error loading dashboard.', false);
    });
}

/* ══════════════════════════════════ ADMIN USERS ════════════════ */
var _admins = [];
var _roles  = [];

function loadAdmins(){
    $.post(B+'admin_users/get_admins',csrf(),function(r){
        if(r.status!=='success'){ tabError('#adminsTbody',9,loadAdmins); toast(r.message||'Failed to load admins',false); return; }
        _admins = r.admins||[];
        renderAdmins();
    },'json').fail(function(){ tabError('#adminsTbody',9,loadAdmins); toast('Network error loading admins.',false); });
}

function renderAdmins(){
    var q = ($('#adminSearch').val()||'').toLowerCase();
    var rows = _admins.filter(function(a){
        if(!q) return true;
        // Search by ID, name, phone, email, or role.
        return [a.adminId, a.name, a.phone, a.email, a.role].some(function(v){
            return (v||'').toString().toLowerCase().indexOf(q) >= 0;
        });
    });
    if(!rows.length){
        $('#adminsTbody').html('<tr><td colspan="9" style="text-align:center;padding:24px;color:var(--t3);">No admin users found.</td></tr>');
        return;
    }
    var h = rows.map(function(a){
        var st = (a.status||'active')==='active' ? '<span class="au-badge au-b-green">Active</span>' : '<span class="au-badge au-b-red">Disabled</span>';
        var isSelf = a.adminId === CURRENT_ADMIN;
        var aid = esc(a.adminId);
        var aname = esc(a.name);
        var acts = '<div style="display:flex;gap:4px;flex-wrap:wrap;">';
        if(isAdmin){
            acts += '<button class="au-btn au-btn-sm au-btn-s act-edit" data-id="'+aid+'" title="Edit" aria-label="Edit '+aname+'"><i class="fa fa-pencil"></i></button>';
            if(!isSelf){
                acts += '<button class="au-btn au-btn-sm au-btn-s act-resetpw" data-id="'+aid+'" data-name="'+aname+'" title="Reset Password" aria-label="Reset password for '+aname+'"><i class="fa fa-key"></i></button>';
                var toggleSt = (a.status||'active')==='active' ? 'disabled' : 'active';
                var toggleIc = toggleSt==='disabled' ? 'fa-ban' : 'fa-check';
                var toggleLbl = (toggleSt==='disabled'?'Disable':'Enable');
                acts += '<button class="au-btn au-btn-sm au-btn-s act-toggle" data-id="'+aid+'" data-status="'+toggleSt+'" title="'+toggleLbl+'" aria-label="'+toggleLbl+' '+aname+'"><i class="fa '+toggleIc+'"></i></button>';
                acts += '<button class="au-btn au-btn-sm au-btn-d act-delete" data-id="'+aid+'" data-name="'+aname+'" title="Delete" aria-label="Delete '+aname+'"><i class="fa fa-trash"></i></button>';
            }
        }
        acts += '</div>';
        return '<tr>'
            +'<td style="font-family:var(--font-m);color:var(--gold);font-size:11.5px;font-weight:600;">'+aid+'</td>'
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
    $('#aPassword').attr('type','password');
    $('#aPwToggle').attr('aria-label','Show password').find('i').attr('class','fa fa-eye');
    $('#autoIdNotice').show();
    $('#passwordRow').show();
    $('#adminModalTitle').html('<i class="fa fa-user-plus" style="color:var(--gold);margin-right:8px;"></i>Add Admin');
    loadRoleOptions();
    $('#adminModal').modal('show');
});

function loadRoleOptions(selected){
    $('#aRole').html('<option value="">Loading roles…</option>');
    $.post(B+'admin_users/get_roles',csrf(),function(r){
        if(r.status!=='success'){
            $('#aRole').html('<option value="">Select role...</option>');
            toast(r.message||'Failed to load roles.',false);
            return;
        }
        _roles = r.roles||[];
        var opts = '<option value="">Select role...</option>';
        var tierLabels = TIER_LABELS;
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
    },'json').fail(function(){
        $('#aRole').html('<option value="">Select role...</option>');
        toast('Network error loading roles.',false);
    });
}

// Show/hide password toggle (create modal)
$('#aPwToggle').on('click', function(){
    var $i = $('#aPassword');
    var show = $i.attr('type') === 'password';
    $i.attr('type', show ? 'text' : 'password');
    $(this).attr('aria-label', show ? 'Hide password' : 'Show password')
           .find('i').attr('class', show ? 'fa fa-eye-slash' : 'fa fa-eye');
});

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
    else {
        var pw = $('#aPassword').val();
        var pe = pwError(pw);
        if(pe){ toast(pe,false); $('#aPassword').focus(); return; }
        payload.password = pw;
    }

    var $btn = $('#saveAdminBtn').prop('disabled',true);
    if(window.ZXLoader) ZXLoader.show(isEdit ? 'Saving changes…' : 'Creating admin…');
    $.post(url, csrf(payload), function(r){
        if(window.ZXLoader) ZXLoader.hide(true);
        $btn.prop('disabled',false);
        if(r.status==='success'){
            $('#adminModal').modal('hide');
            if(!isEdit && r.admin_id){
                // Show credentials dialog for newly created admin
                $('#credAdminId').text(r.admin_id);
                $('#credLoginId').text(r.admin_id);
                $('#credName').text(r.name||payload.name);
                $('#credRole').text(r.role||payload.role);
                // F8: the server no longer echoes the password; use the value the
                // creator just typed (already in the payload) for the one-time reveal.
                $('#credPassword').text(payload.password||'********');
                $('#credentialsModal').modal('show');
            } else {
                toast(r.message,true);
            }
            loadAdmins();
            loadDashboard();
        } else {
            toast(r.message,false);
        }
    },'json').fail(function(){
        if(window.ZXLoader) ZXLoader.hide(true);
        $btn.prop('disabled',false);
        toast('Network error — please try again.',false);
    });
});

// Toggle status (delegated)
$(document).on('click', '.act-toggle', function(){
    var $b = $(this), id = String($b.attr('data-id')||''), status = $b.attr('data-status');
    if($b.prop('disabled')) return;
    if(!confirm((status==='disabled'?'Disable':'Enable')+' this admin?')) return;
    $b.prop('disabled',true);
    $.post(B+'admin_users/disable_admin',csrf({admin_id:id, status:status}),function(r){
        if(r.status==='success'){ loadAdmins(); loadDashboard(); toast(r.message,true); }
        else { $b.prop('disabled',false); toast(r.message,false); }
    },'json').fail(function(){ $b.prop('disabled',false); toast('Network error.',false); });
});

// Delete (delegated)
$(document).on('click', '.act-delete', function(){
    var $b = $(this), id = String($b.attr('data-id')||''), name = $b.attr('data-name');
    if($b.prop('disabled')) return;
    if(!confirm('Permanently delete admin "'+name+'"? This cannot be undone.')) return;
    $b.prop('disabled',true);
    $.post(B+'admin_users/delete_admin',csrf({admin_id:id}),function(r){
        if(r.status==='success'){ loadAdmins(); loadDashboard(); toast(r.message,true); }
        else { $b.prop('disabled',false); toast(r.message,false); }
    },'json').fail(function(){ $b.prop('disabled',false); toast('Network error.',false); });
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
    var pe = pwError(pw);
    if(pe){ toast(pe,false); $('#resetPwInput').focus(); return; }
    var $sb = $(this).find('[type="submit"]').prop('disabled',true);
    $.post(B+'admin_users/reset_password',csrf({admin_id:$('#resetPwAdminId').val(), new_password:pw}),function(r){
        $sb.prop('disabled',false);
        if(r.status==='success'){ $('#resetPwModal').modal('hide'); toast(r.message,true); }
        else { toast(r.message,false); }
    },'json').fail(function(){ $sb.prop('disabled',false); toast('Network error.',false); });
});

/* Role definition/permission editing was moved to Departments & Roles →
   "Access Roles" tab (single source of truth). The get_roles/save_role/
   delete_role endpoints stay in AdminUsers and back that tab plus the
   Add-Admin role dropdown (loadRoleOptions above). */

/* ══════════════════════════════════ ACCESS (role → panel sections) ═════ */
var _accessRoles = [];
var _accessSel   = null;   // currently selected ROLE_* id

function loadAccess(){
    $('#accessListWrap').html('<p style="text-align:center;color:var(--t3);padding:20px;"><i class="fa fa-spinner fa-spin"></i></p>');
    $.post(B+'admin_users/get_roles',csrf(),function(r){
        if(r.status!=='success'){ tabError('#accessListWrap',0,loadAccess); toast(r.message||'Failed to load roles.',false); return; }
        _accessRoles = r.roles||[];
        if(r.modules && r.modules.length) MODULES = r.modules;
        renderAccessList();
        // keep current selection if still present
        if(_accessSel && _accessRoles.some(function(x){return x.id===_accessSel;})) showAccessEditor(_accessSel);
    },'json').fail(function(){ tabError('#accessListWrap',0,loadAccess); toast('Network error loading roles.',false); });
}

function renderAccessList(){
    var q = ($('#accessSearch').val()||'').toLowerCase();
    var tierLabels = TIER_LABELS;
    var rows = _accessRoles.filter(function(r){ return !q || (r.label||'').toLowerCase().indexOf(q)>=0; });
    if(!rows.length){ $('#accessListWrap').html('<p style="text-align:center;color:var(--t3);padding:20px;">No roles found.</p>'); return; }
    var lastTier=0, h='';
    rows.forEach(function(rl){
        var tier = rl.tier || 7;
        if(tier!==lastTier){ lastTier=tier; h += '<div style="padding:6px 4px 3px;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--t3);font-family:var(--font-m);">'+esc(tierLabels[tier]||'Other')+'</div>'; }
        var n = (rl.permissions||[]).length;
        var on = rl.id===_accessSel;
        h += '<button type="button" class="access-item" data-id="'+esc(rl.id)+'" aria-pressed="'+(on?'true':'false')+'" style="width:100%;text-align:left;font:inherit;padding:10px 12px;border:1px solid '+(on?'var(--gold-ring)':'var(--border)')+';border-radius:8px;margin-bottom:6px;cursor:pointer;background:'+(on?'var(--gold-dim)':'transparent')+';display:flex;align-items:center;gap:8px;">'
            +'<strong style="color:var(--t1);font-size:12.5px;flex:1;">'+esc(rl.label||rl.id)+'</strong>'
            +'<span class="au-badge '+(n?'au-b-blue':'au-b-gray')+'" style="font-size:9.5px;">'+n+' section'+(n===1?'':'s')+'</span></button>';
    });
    $('#accessListWrap').html(h);
}

$(document).on('click', '.access-item', function(){ showAccessEditor(String($(this).attr('data-id')||'')); });
$('#accessSearch').on('input', renderAccessList);

function showAccessEditor(id){
    var rl = _accessRoles.find(function(x){ return x.id===id; });
    if(!rl) return;
    _accessSel = id;
    renderAccessList();
    $('#accessPlaceholder').hide();
    $('#accessEditorBox').show();
    $('#accessRoleName').text(rl.label||rl.id);
    $('#accessRoleId').val(id);
    var sel = rl.permissions||[];
    $('#accessPermGrid').html(buildPermPicker(sel));
    updateAccessCount();
    // Delete is offered only for custom (non-system) roles, to admins.
    $('#deleteRoleBtn').toggle(isAdmin && !rl.is_system)
        .data('role-id', id).data('role-label', rl.label||id);
}
// Renders the grouped permission picker markup for a given selected-list.
function buildPermPicker(sel){
    function chip(m){
        var isChild = !!MODULE_PARENT[m];
        var parentOn = isChild && sel.indexOf(MODULE_PARENT[m])>=0;
        var on = sel.indexOf(m)>=0 || parentOn;
        // A child whose parent umbrella is granted is "included" — shown on but
        // not individually toggleable (uncheck the parent to fine-tune).
        var cls = 'au-perm-chip'+(on?' selected':'')+(isChild?' sub':'')+(parentOn?' included':'');
        var lock = parentOn ? '<i class="fa fa-lock lock"></i>' : '';
        return '<button type="button" class="'+cls+'" data-mod="'+esc(m)+'"'
             + (isChild?' data-parent="'+esc(MODULE_PARENT[m])+'"':'')+' aria-pressed="'+(on?'true':'false')+'">'
             + '<span class="box">'+CHECK_SVG+'</span>'+esc(m)+lock+'</button>';
    }
    // Any module not placed in a category → collected into a trailing "Other" group.
    var placed = {};
    MODULE_CATS.forEach(function(c){ c.mods.forEach(function(m){ placed[m]=1; (MODULE_CHILDREN[m]||[]).forEach(function(x){placed[x]=1;}); }); });
    var cats = MODULE_CATS.slice();
    // Anything the server exposes but no category placed: top-level modules AND
    // any child whose parent isn't itself exposed (M11 — never drop a permission).
    var leftover = MODULES.filter(function(m){
        if(placed[m]) return false;
        var p = MODULE_PARENT[m];
        return !p || MODULES.indexOf(p) < 0;
    });
    if(leftover.length) cats.push({h:'Other', mods:leftover});

    var h = '';
    cats.forEach(function(cat){
        var body = '';
        cat.mods.forEach(function(m){
            if(!(MODULES.indexOf(m)>=0)) return;      // skip modules the server didn't expose
            if(MODULE_CHILDREN[m]){
                // Parent with a nested sub-module tray.
                var kids = (MODULE_CHILDREN[m]||[]).filter(function(c){ return MODULES.indexOf(c)>=0; });
                var sub = kids.map(chip).join('');
                body += '<div class="au-parent-wrap"><div class="au-perm-grid">'+chip(m)+'</div>'
                      + '<div class="au-subgroup"><div class="sg-h">'+esc(m)+' sub-modules — grant individually, or all via '+esc(m)+'</div>'
                      + '<div class="au-perm-grid">'+sub+'</div></div></div>';
            } else {
                body += chip(m);
            }
        });
        if(body==='') return;
        h += '<div class="au-cat"><div class="au-cat-h">'+esc(cat.h)+'</div><div class="au-perm-grid">'+body+'</div></div>';
    });
    return h;
}
function updateAccessCount(){
    var n = $('#accessPermGrid .au-perm-chip.selected:not(.included)').length;
    $('#accessCount').text(n);
}

// One toggle handler for BOTH pickers (the Access editor and the New-role modal).
$(document).on('click', '.au-perm-picker .au-perm-chip', function(){
    if(!isAdmin) return;
    var $c = $(this), mod = $c.attr('data-mod'), $grid = $c.closest('.au-perm-picker');
    // Included children are controlled by their parent umbrella — not directly.
    if($c.hasClass('included')) return;
    $c.toggleClass('selected');
    $c.attr('aria-pressed', $c.hasClass('selected') ? 'true' : 'false');
    // Toggling a parent cascades its "included" state onto its children chips.
    if(MODULE_CHILDREN[mod]){
        var on = $c.hasClass('selected');
        $grid.find('.au-perm-chip[data-parent="'+mod+'"]').each(function(){
            $(this).toggleClass('included selected', on).attr('aria-pressed', on?'true':'false');
            $(this).find('.lock').remove();
            if(on) $(this).append('<i class="fa fa-lock lock"></i>');
        });
    }
    if($grid.attr('id')==='accessPermGrid') updateAccessCount();
});

$('#saveAccessBtn').on('click', function(){
    if(!isAdmin){ alert('Only Admin role can change access.'); return; }
    var id = $('#accessRoleId').val();
    if(!id) return;
    var perms = [];
    // Save only explicitly-selected chips; an "included" child is covered by its
    // parent umbrella already, so we don't persist it redundantly.
    $('#accessPermGrid .au-perm-chip.selected:not(.included)').each(function(){ perms.push($(this).attr('data-mod')); });
    var $btn = $('#saveAccessBtn').prop('disabled',true);
    $.post(B+'admin_users/save_role',csrf({ role_id:id, 'permissions[]': perms.length?perms:['_none_'] }),function(r){
        $btn.prop('disabled',false);
        if(r.status==='success'){
            toast(r.message||'Access updated.',true);
            // reflect new count locally + refresh list
            var rl = _accessRoles.find(function(x){ return x.id===id; });
            if(rl) rl.permissions = r.permissions||perms.filter(function(p){return p!=='_none_';});
            renderAccessList();
            $('#accessCount').text((r.permissions||[]).length);
        } else { toast(r.message||'Failed.',false); }
    },'json').fail(function(){ $btn.prop('disabled',false); toast('Network error.',false); });
});

/* ── Delete a custom role (canonical Org::delete_role — blocks system roles
      and any role still held by staff; also scrubs it from departments). ── */
$('#deleteRoleBtn').on('click', function(){
    if(!isAdmin) return;
    var id = $(this).data('role-id'), label = $(this).data('role-label')||'this role';
    if(!id) return;
    if(!confirm('Delete the role "'+label+'"? It will be removed from every department too. This cannot be undone.')) return;
    var $btn = $(this).prop('disabled',true);
    $.post(B+'org/delete_role', csrf({ role_id:id }), function(r){
        $btn.prop('disabled',false);
        if(r.status==='success'){
            toast(r.message||'Role deleted.',true);
            _accessSel = null;
            $('#accessEditorBox').hide(); $('#accessPlaceholder').show();
            loadAccess();
        } else { toast(r.message||'Could not delete role.',false); }
    },'json').fail(function(){ $btn.prop('disabled',false); toast('Network error.',false); });
});

/* ── Create a new role (name + permissions) — writes via the canonical
      Org::save_role, which auto-generates the ROLE_ reference id. ── */
function slugRoleRef(s){ var t=(s||'').toUpperCase().replace(/[^A-Z0-9]+/g,'_').replace(/^_+|_+$/g,''); return t?('ROLE_'+t):'—'; }
$('#newRoleBtn').on('click', function(){
    if(!isAdmin){ alert('Only Admin / School Super Admin can create roles.'); return; }
    $('#newRoleName').val('');
    $('#newRoleRef').text('—');
    $('#newRoleCategory').val('Administrative');
    $('#newRolePermGrid').html(buildPermPicker([]));
    $('#newRoleModal').modal('show');
    setTimeout(function(){ $('#newRoleName').trigger('focus'); },300);
});
$('#newRoleName').on('input', function(){ $('#newRoleRef').text(slugRoleRef($(this).val())); });
// F16: Enter in the name field submits, matching the other modals' <form> behaviour.
$('#newRoleName').on('keydown', function(e){ if(e.key==='Enter'){ e.preventDefault(); $('#createRoleBtn').click(); } });
$('#createRoleBtn').on('click', function(){
    var name = $.trim($('#newRoleName').val());
    if(!name){ alert('Please enter a role name.'); $('#newRoleName').trigger('focus'); return; }
    var perms = [];
    $('#newRolePermGrid .au-perm-chip.selected:not(.included)').each(function(){ perms.push($(this).attr('data-mod')); });
    var params = { role_id:'', label:name, category:$('#newRoleCategory').val(), permissions_present:'1' };
    if(perms.length) params['permissions[]'] = perms;
    var $btn = $('#createRoleBtn').prop('disabled',true);
    $.post(B+'org/save_role', csrf(params), function(r){
        $btn.prop('disabled',false);
        if(r.status==='success'){
            $('#newRoleModal').modal('hide');
            toast(r.message||'Role created.',true);
            loadAccess();   // refresh the roles list so the new role appears
        } else { toast(r.message||'Could not create role.',false); }
    },'json').fail(function(){ $btn.prop('disabled',false); toast('Network error.',false); });
});

/* ══════════════════════════════════ LOGIN ACTIVITY ═════════════ */
var _logs = [];
var _logFilter = 'all';
var _logsCapped = false;

// Condense a raw user-agent into a short "Browser · OS" label.
function shortUA(ua){
    ua = ua || '';
    if(!ua) return '-';
    var b = /Edg/.test(ua)?'Edge':/OPR|Opera/.test(ua)?'Opera':/Chrome/.test(ua)?'Chrome':/Firefox/.test(ua)?'Firefox':/Safari/.test(ua)?'Safari':'';
    var o = /Windows/.test(ua)?'Windows':/Android/.test(ua)?'Android':/iPhone|iPad|iOS/.test(ua)?'iOS':/Mac OS X|Macintosh/.test(ua)?'macOS':/Linux/.test(ua)?'Linux':'';
    var label = [b,o].filter(Boolean).join(' · ');
    return label || ua.substring(0,28);
}

function statusBadge(s){
    if(s==='failed') return '<span class="au-badge au-b-red">Failed</span>';
    if(s==='locked') return '<span class="au-badge au-b-amber">Locked</span>';
    return '<span class="au-badge au-b-green">Success</span>';
}

function loadLogs(){
    $.post(B+'admin_users/get_login_logs',csrf(),function(r){
        if(r.status!=='success'){ tabError('#logsTbody',5,loadLogs); toast(r.message||'Failed to load login activity.',false); return; }
        _logs = r.logs||[];
        _logsCapped = !!r.capped;
        renderLogs();
    },'json').fail(function(){ tabError('#logsTbody',5,loadLogs); toast('Network error loading login activity.',false); });
}

function renderLogs(){
    var q = ($('#logSearch').val()||'').toLowerCase();
    var rows = _logs.filter(function(l){
        if(_logFilter!=='all' && (l.status||'success')!==_logFilter) return false;
        if(q && (l.adminId||'').toLowerCase().indexOf(q)<0 && (l.adminName||'').toLowerCase().indexOf(q)<0 && (l.ipAddress||'').toLowerCase().indexOf(q)<0) return false;
        return true;
    });
    $('#logsMeta').text(rows.length+' of '+_logs.length+' event'+(_logs.length===1?'':'s')+(_logsCapped?' (showing latest 200)':''));
    if(!rows.length){
        $('#logsTbody').html('<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--t3);">No matching entries.</td></tr>');
        return;
    }
    var h = rows.map(function(l){
        return '<tr>'
            +'<td style="font-size:11.5px;font-family:var(--font-m);color:var(--t3);">'+esc(ts(l.loginTime))+'</td>'
            +'<td>'+esc(l.adminName||l.adminId||'')+'<div style="font-size:10px;color:var(--t3);">'+esc(l.adminId||'')+'</div></td>'
            +'<td style="font-size:11.5px;font-family:var(--font-m);">'+esc(l.ipAddress||'')+'</td>'
            +'<td style="font-size:11px;color:var(--t3);" title="'+esc(l.device||'')+'">'+esc(shortUA(l.device))+'</td>'
            +'<td>'+statusBadge(l.status)+'</td></tr>';
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
