<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>

<style>
/* ── Device Management Module ──────────────────────────────────────── */
.dm-wrap{padding:20px;max-width:1440px;margin:0 auto}
.dm-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px}
.dm-header-title{font-family:var(--font-b);font-size:1.3rem;font-weight:700;color:var(--t1);display:flex;align-items:center;gap:8px}
.dm-header-title i{color:var(--gold);font-size:1.1rem}
.dm-breadcrumb{list-style:none;display:flex;gap:6px;font-size:12px;color:var(--t3);padding:0;margin:6px 0 0;font-family:var(--font-b)}
.dm-breadcrumb a{color:var(--gold);text-decoration:none}
.dm-breadcrumb li+li::before{content:">";margin-right:6px;color:var(--t4)}

/* Tabs */
.dm-tabs{display:flex;gap:4px;margin-bottom:20px;flex-wrap:wrap}
.dm-tab{padding:8px 18px;border-radius:8px;font-size:12.5px;font-weight:600;font-family:var(--font-b);
    cursor:pointer;background:var(--bg3);color:var(--t3);border:1px solid var(--border);transition:all .15s var(--ease)}
.dm-tab:hover{color:var(--t1);border-color:var(--gold-ring)}
.dm-tab.active{background:var(--gold);color:#fff;border-color:var(--gold)}
.dm-pane{display:none}.dm-pane.active{display:block}

/* KPIs */
.dm-kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(175px,1fr));gap:14px;margin-bottom:20px}
.dm-kpi-card{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:18px 16px;text-align:center;transition:border-color .15s}
.dm-kpi-card:hover{border-color:var(--gold-ring)}
.dm-kpi-num{font-size:28px;font-weight:800;font-family:var(--font-b);line-height:1;color:var(--t1)}
.dm-kpi-lbl{font-size:10.5px;color:var(--t3);margin-top:5px;text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m)}
.dm-kpi-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;margin:0 auto 10px;font-size:16px}
.dm-kpi-icon.teal{background:rgba(15,118,110,.12);color:var(--gold)}
.dm-kpi-icon.blue{background:rgba(59,130,246,.12);color:#3b82f6}
.dm-kpi-icon.rose{background:rgba(220,38,38,.10);color:#dc2626}
.dm-kpi-icon.amber{background:rgba(217,119,6,.12);color:#d97706}

/* Box */
.dm-box{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:20px;margin-bottom:18px}
.dm-box-head{font-size:14px;font-weight:700;color:var(--t1);font-family:var(--font-b);margin-bottom:14px;
    padding-bottom:10px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}

/* Table */
.dm-table-wrap{max-height:520px;overflow-y:auto;border:1px solid var(--border);border-radius:8px}
.dm-table{width:100%;border-collapse:collapse}
.dm-table th{padding:10px 12px;background:var(--bg3);color:var(--t3);font-size:10.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m);border-bottom:2px solid var(--border);
    text-align:left;position:sticky;top:0;z-index:1}
.dm-table td{padding:9px 12px;border-bottom:1px solid var(--border);color:var(--t1);font-size:12.5px;font-family:var(--font-b)}
.dm-table tr:hover td{background:var(--gold-dim)}

/* Badges */
.dm-badge{display:inline-block;padding:3px 10px;border-radius:20px;font-size:10.5px;font-weight:600;letter-spacing:.3px}
.dm-b-teal{background:rgba(15,118,110,.12);color:var(--gold)}
.dm-b-blue{background:rgba(59,130,246,.12);color:#3b82f6}
.dm-b-amber{background:rgba(217,119,6,.12);color:#d97706}
.dm-b-green{background:rgba(22,163,74,.12);color:#16a34a}
.dm-b-rose{background:rgba(220,38,38,.10);color:#dc2626}
.dm-b-purple{background:rgba(139,92,246,.12);color:#8b5cf6}
.dm-b-gray{background:rgba(107,114,128,.12);color:#6b7280}

/* Buttons */
.dm-btn{padding:8px 18px;border-radius:7px;font-size:12.5px;font-weight:600;border:none;cursor:pointer;
    font-family:var(--font-b);transition:all .15s var(--ease);display:inline-flex;align-items:center;gap:6px}
.dm-btn-p{background:var(--gold);color:#fff}.dm-btn-p:hover{background:var(--gold2)}
.dm-btn-s{background:var(--bg3);color:var(--t2);border:1px solid var(--border)}.dm-btn-s:hover{border-color:var(--gold-ring)}
.dm-btn-d{background:rgba(220,38,38,.10);color:#dc2626;border:1px solid rgba(220,38,38,.2)}.dm-btn-d:hover{background:rgba(220,38,38,.18)}
.dm-btn-sm{padding:5px 12px;font-size:11.5px}
.dm-btn:disabled{opacity:.5;cursor:not-allowed}

/* Search */
.dm-search-bar{display:flex;gap:10px;align-items:stretch;margin-bottom:16px}
.dm-search-input{flex:1;padding:9px 14px;border:1px solid var(--border);background:var(--bg3);border-radius:8px;
    font-size:13px;color:var(--t1);font-family:var(--font-b);transition:border-color .15s}
.dm-search-input:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-ring)}
.dm-search-input::placeholder{color:var(--t4)}

/* User Profile Card */
.dm-user-card{display:flex;gap:16px;align-items:center;padding:16px;background:var(--bg3);border-radius:10px;border:1px solid var(--border);margin-bottom:16px}
.dm-user-avatar{width:48px;height:48px;border-radius:50%;background:var(--gold);display:flex;align-items:center;justify-content:center;
    font-size:18px;font-weight:800;color:#fff;font-family:var(--font-d);flex-shrink:0}
.dm-user-info{flex:1;min-width:0}
.dm-user-name{font-size:15px;font-weight:700;color:var(--t1);font-family:var(--font-b)}
.dm-user-meta{font-size:11.5px;color:var(--t3);font-family:var(--font-m);margin-top:2px}

/* Device Card */
.dm-device-list{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:12px;margin-top:14px}
.dm-device-card{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:16px;transition:border-color .15s}
.dm-device-card:hover{border-color:var(--gold-ring)}
.dm-device-card.blocked{border-color:rgba(220,38,38,.3);background:rgba(220,38,38,.03)}
.dm-device-header{display:flex;align-items:center;gap:10px;margin-bottom:10px}
.dm-device-icon{width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:16px;flex-shrink:0}
.dm-device-icon.android{background:rgba(22,163,74,.12);color:#16a34a}
.dm-device-icon.ios{background:rgba(59,130,246,.12);color:#3b82f6}
.dm-device-icon.other{background:rgba(107,114,128,.12);color:#6b7280}
.dm-device-title{font-size:13px;font-weight:700;color:var(--t1);font-family:var(--font-b)}
.dm-device-detail{font-size:11.5px;color:var(--t3);margin-bottom:3px;font-family:var(--font-m);display:flex;align-items:center;gap:6px}
.dm-device-detail i{width:14px;text-align:center;color:var(--t4);font-size:10px}
.dm-device-actions{display:flex;gap:6px;margin-top:10px;padding-top:10px;border-top:1px solid var(--border)}

/* Filters */
.dm-filters{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:14px}
.dm-fg{display:flex;flex-direction:column;gap:3px}
.dm-fg label{font-size:10px;font-weight:600;color:var(--t3);text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m)}
.dm-fg input,.dm-fg select{padding:6px 10px;border:1px solid var(--border);background:var(--bg3);
    border-radius:6px;font-size:12px;color:var(--t1);font-family:var(--font-b);min-width:130px}
.dm-fg input:focus,.dm-fg select:focus{outline:none;border-color:var(--gold);box-shadow:0 0 0 3px var(--gold-ring)}

/* Charts row */
.dm-chart-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:18px}
@media(max-width:768px){.dm-chart-row{grid-template-columns:1fr}}
.dm-chart-box{background:var(--bg2);border:1px solid var(--border);border-radius:10px;padding:16px}
.dm-chart-title{font-size:12.5px;font-weight:700;color:var(--t1);font-family:var(--font-b);margin-bottom:12px}

/* Activity timeline */
.dm-timeline{max-height:340px;overflow-y:auto;padding-right:8px}
.dm-tl-item{display:flex;gap:10px;padding:8px 0;border-bottom:1px solid var(--border)}
.dm-tl-item:last-child{border-bottom:none}
.dm-tl-dot{width:8px;height:8px;border-radius:50%;background:var(--gold);flex-shrink:0;margin-top:5px}
.dm-tl-content{flex:1;min-width:0}
.dm-tl-text{font-size:12px;color:var(--t1);font-family:var(--font-b)}
.dm-tl-time{font-size:10.5px;color:var(--t4);font-family:var(--font-m);margin-top:2px}

/* Alert cards */
.dm-alert-section{margin-bottom:18px}
.dm-alert-title{font-size:13px;font-weight:700;color:var(--t1);font-family:var(--font-b);margin-bottom:10px;display:flex;align-items:center;gap:8px}
.dm-alert-title i{font-size:14px}
.dm-alert-count{display:inline-block;padding:2px 8px;border-radius:12px;font-size:10px;font-weight:700;font-family:var(--font-m);margin-left:6px}
.dm-alert-item{padding:10px 14px;border:1px solid var(--border);border-radius:8px;margin-bottom:6px;background:var(--bg2);
    font-size:12px;font-family:var(--font-b);color:var(--t1);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.dm-alert-item:hover{border-color:var(--gold-ring)}

/* DataTables override within this module */
.dm-box .dataTables_wrapper{font-family:var(--font-b);font-size:12px;color:var(--t2)}
.dm-box .dataTables_wrapper .dt-search input{padding:5px 10px;border:1px solid var(--border);background:var(--bg3);
    border-radius:6px;font-size:12px;color:var(--t1)}
.dm-box .dataTables_wrapper .dt-length select{padding:4px 8px;border:1px solid var(--border);background:var(--bg3);
    border-radius:6px;font-size:12px;color:var(--t1)}
.dm-box table.dataTable thead th{background:var(--bg3);color:var(--t3);font-size:10.5px;font-weight:700;
    text-transform:uppercase;letter-spacing:.4px;font-family:var(--font-m);border-bottom:2px solid var(--border);padding:10px 12px}
.dm-box table.dataTable tbody td{padding:9px 12px;border-bottom:1px solid var(--border);color:var(--t1);font-size:12.5px}
.dm-box table.dataTable tbody tr:hover td{background:var(--gold-dim)}

/* Empty state */
.dm-empty{text-align:center;padding:40px 20px;color:var(--t3);font-family:var(--font-b)}
.dm-empty i{font-size:36px;display:block;margin-bottom:12px;opacity:.5}

/* Toast */
.dm-toast{position:fixed;top:20px;right:20px;z-index:10000;padding:12px 20px;border-radius:8px;font-size:13px;font-weight:600;
    font-family:var(--font-b);color:#fff;display:none;max-width:420px;box-shadow:0 8px 24px rgba(0,0,0,.15)}
.dm-toast.success{background:#22c55e;display:block}.dm-toast.error{background:#ef4444;display:block}

/* Spinner */
.dm-spinner{display:inline-block;width:16px;height:16px;border:2px solid var(--t4);border-top-color:var(--gold);border-radius:50%;animation:dm-spin .6s linear infinite}
@keyframes dm-spin{to{transform:rotate(360deg)}}

/* Expand row for DataTable */
.dm-expand-row td{padding:0 !important;background:var(--bg3) !important}
.dm-expand-inner{padding:12px 20px}
</style>

<div class="content-wrapper"><section class="content"><div class="dm-wrap">

<!-- Header -->
<div class="dm-header"><div>
    <div class="dm-header-title"><i class="fa fa-mobile"></i> Device Management</div>
    <ol class="dm-breadcrumb">
        <li><a href="<?= base_url('admin') ?>">Dashboard</a></li>
        <li>Device Management</li>
    </ol>
</div>
<div style="display:flex;gap:8px">
    <button class="dm-btn dm-btn-sm dm-btn-s" onclick="DM.refreshCurrent()"><i class="fa fa-refresh"></i> Refresh</button>
</div>
</div>

<!-- Tabs -->
<div class="dm-tabs">
    <div class="dm-tab active" data-tab="dashboard"><i class="fa fa-dashboard" style="margin-right:5px"></i>Dashboard</div>
    <div class="dm-tab" data-tab="user"><i class="fa fa-user" style="margin-right:5px"></i>User Devices</div>
    <div class="dm-tab" data-tab="bulk"><i class="fa fa-list" style="margin-right:5px"></i>Bulk Overview</div>
    <div class="dm-tab" data-tab="security"><i class="fa fa-shield" style="margin-right:5px"></i>Security Alerts</div>
</div>

<!-- ════════════════════════════════════════════ TAB 1: DASHBOARD ════ -->
<div class="dm-pane active" id="pane-dashboard">

    <div class="dm-kpi" id="dmKpiRow">
        <div class="dm-kpi-card">
            <div class="dm-kpi-icon teal"><i class="fa fa-users"></i></div>
            <div class="dm-kpi-num" id="kpi-users-devices">-</div>
            <div class="dm-kpi-lbl">Users with Devices</div>
        </div>
        <div class="dm-kpi-card">
            <div class="dm-kpi-icon blue"><i class="fa fa-mobile"></i></div>
            <div class="dm-kpi-num" id="kpi-total-bound" style="color:#3b82f6">-</div>
            <div class="dm-kpi-lbl">Total Bound Devices</div>
        </div>
        <div class="dm-kpi-card">
            <div class="dm-kpi-icon rose"><i class="fa fa-ban"></i></div>
            <div class="dm-kpi-num" id="kpi-blocked" style="color:#dc2626">-</div>
            <div class="dm-kpi-lbl">Blocked Devices</div>
        </div>
        <div class="dm-kpi-card">
            <div class="dm-kpi-icon amber"><i class="fa fa-bar-chart"></i></div>
            <div class="dm-kpi-num" id="kpi-avg" style="color:#d97706">-</div>
            <div class="dm-kpi-lbl">Avg Devices / User</div>
        </div>
    </div>

    <div class="dm-chart-row">
        <div class="dm-chart-box">
            <div class="dm-chart-title"><i class="fa fa-bar-chart" style="color:var(--gold);margin-right:6px"></i>Device Distribution</div>
            <canvas id="chartDistro" height="200"></canvas>
        </div>
        <div class="dm-chart-box">
            <div class="dm-chart-title"><i class="fa fa-pie-chart" style="color:var(--gold);margin-right:6px"></i>Platform Distribution</div>
            <canvas id="chartPlatform" height="200"></canvas>
        </div>
    </div>

    <div class="dm-box">
        <div class="dm-box-head"><span><i class="fa fa-clock-o" style="color:var(--gold);margin-right:6px"></i>Recent Device Activity</span></div>
        <div class="dm-timeline" id="dmTimeline">
            <div class="dm-empty"><i class="fa fa-spinner fa-spin"></i>Loading...</div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════ TAB 2: USER DEVICES ════ -->
<div class="dm-pane" id="pane-user">

    <div class="dm-box">
        <div class="dm-box-head"><span><i class="fa fa-search" style="color:var(--gold);margin-right:6px"></i>Search User</span></div>
        <div class="dm-search-bar">
            <input type="text" class="dm-search-input" id="userSearchInput" placeholder="Search by User ID or Name (e.g. TEA0001, John)..." autocomplete="off">
            <button class="dm-btn dm-btn-p" id="btnSearchUser" onclick="DM.searchUser()"><i class="fa fa-search"></i> Search</button>
        </div>
        <div id="searchResults" style="display:none">
            <div class="dm-table-wrap" style="max-height:240px">
                <table class="dm-table">
                    <thead><tr><th>User ID</th><th>Name</th><th>Role</th><th>Devices</th><th>Status</th><th>Action</th></tr></thead>
                    <tbody id="searchResultsTbody"></tbody>
                </table>
            </div>
        </div>
    </div>

    <div id="selectedUserSection" style="display:none">
        <div class="dm-user-card" id="selectedUserCard"></div>

        <div class="dm-box">
            <div class="dm-box-head">
                <span><i class="fa fa-mobile" style="color:var(--gold);margin-right:6px"></i>Bound Devices</span>
                <span id="deviceCountBadge" class="dm-badge dm-b-teal"></span>
                <button class="dm-btn dm-btn-sm dm-btn-d" id="btnBulkRemove" onclick="DM.confirmBulkRemove()" style="display:none"><i class="fa fa-trash"></i> Remove All Devices</button>
            </div>
            <div class="dm-device-list" id="deviceList">
                <div class="dm-empty"><i class="fa fa-spinner fa-spin"></i>Loading devices...</div>
            </div>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════ TAB 3: BULK OVERVIEW ════ -->
<div class="dm-pane" id="pane-bulk">

    <div class="dm-box">
        <div class="dm-box-head">
            <span><i class="fa fa-table" style="color:var(--gold);margin-right:6px"></i>All Users &amp; Devices</span>
            <button class="dm-btn dm-btn-sm dm-btn-s" onclick="DM.loadBulk()"><i class="fa fa-refresh"></i> Reload</button>
        </div>
        <div class="dm-filters">
            <div class="dm-fg">
                <label>Role</label>
                <select id="bulkFilterRole">
                    <option value="">All</option>
                    <option value="Teacher">Teacher</option>
                    <option value="Student">Student</option>
                </select>
            </div>
            <div class="dm-fg">
                <label>Min Devices</label>
                <input type="number" id="bulkFilterMin" min="0" value="" placeholder="0">
            </div>
            <div class="dm-fg">
                <label>Status</label>
                <select id="bulkFilterStatus">
                    <option value="">All</option>
                    <option value="ok">OK</option>
                    <option value="has_blocked">Has Blocked</option>
                    <option value="over_limit">Over Limit</option>
                </select>
            </div>
        </div>
        <table id="bulkTable" class="display" style="width:100%">
            <thead><tr>
                <th></th>
                <th>User ID</th>
                <th>Name</th>
                <th>Role</th>
                <th>Active Devices</th>
                <th>Blocked</th>
                <th>Status</th>
                <th>Action</th>
            </tr></thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- ════════════════════════════════════════════ TAB 4: SECURITY ALERTS ════ -->
<div class="dm-pane" id="pane-security">

    <div class="dm-kpi" id="securityKpi">
        <div class="dm-kpi-card">
            <div class="dm-kpi-icon rose"><i class="fa fa-ban"></i></div>
            <div class="dm-kpi-num" id="sec-kpi-blocked" style="color:#dc2626">-</div>
            <div class="dm-kpi-lbl">Blocked Device Users</div>
        </div>
        <div class="dm-kpi-card">
            <div class="dm-kpi-icon amber"><i class="fa fa-exclamation-triangle"></i></div>
            <div class="dm-kpi-num" id="sec-kpi-overlimit" style="color:#d97706">-</div>
            <div class="dm-kpi-lbl">Over Device Limit</div>
        </div>
        <div class="dm-kpi-card">
            <div class="dm-kpi-icon blue"><i class="fa fa-clock-o"></i></div>
            <div class="dm-kpi-num" id="sec-kpi-stale" style="color:#3b82f6">-</div>
            <div class="dm-kpi-lbl">Stale Devices</div>
        </div>
        <div class="dm-kpi-card">
            <div class="dm-kpi-icon purple" style="background:rgba(139,92,246,.12);color:#8b5cf6"><i class="fa fa-bolt"></i></div>
            <div class="dm-kpi-num" id="sec-kpi-rapid" style="color:#8b5cf6">-</div>
            <div class="dm-kpi-lbl">Rapid Binding</div>
        </div>
    </div>

    <!-- Blocked Devices -->
    <div class="dm-alert-section" id="secBlockedSection">
        <div class="dm-alert-title"><i class="fa fa-ban" style="color:#dc2626"></i> Users with Blocked Devices <span class="dm-alert-count dm-b-rose" id="secBlockedCount">0</span></div>
        <div id="secBlockedList"><div class="dm-empty"><i class="fa fa-spinner fa-spin"></i>Loading...</div></div>
    </div>

    <!-- Over Limit -->
    <div class="dm-alert-section" id="secOverLimitSection">
        <div class="dm-alert-title"><i class="fa fa-exclamation-triangle" style="color:#d97706"></i> Users Exceeding Device Limit <span class="dm-alert-count dm-b-amber" id="secOverLimitCount">0</span></div>
        <div id="secOverLimitList"><div class="dm-empty"><i class="fa fa-spinner fa-spin"></i>Loading...</div></div>
    </div>

    <!-- Stale Devices -->
    <div class="dm-alert-section" id="secStaleSection">
        <div class="dm-alert-title"><i class="fa fa-clock-o" style="color:#3b82f6"></i> Devices Not Used in 30+ Days <span class="dm-alert-count dm-b-blue" id="secStaleCount">0</span></div>
        <div id="secStaleList"><div class="dm-empty"><i class="fa fa-spinner fa-spin"></i>Loading...</div></div>
    </div>

    <!-- Rapid Binding -->
    <div class="dm-alert-section" id="secRapidSection">
        <div class="dm-alert-title"><i class="fa fa-bolt" style="color:#8b5cf6"></i> Suspicious Rapid Binding <span class="dm-alert-count dm-b-purple" id="secRapidCount">0</span></div>
        <div id="secRapidList"><div class="dm-empty"><i class="fa fa-spinner fa-spin"></i>Loading...</div></div>
    </div>

    <div style="text-align:center;margin-top:12px">
        <button class="dm-btn dm-btn-sm dm-btn-s" onclick="DM.loadSecurityAlerts()"><i class="fa fa-refresh"></i> Refresh Alerts</button>
    </div>
</div>

</div></section></div>

<!-- Toast -->
<div class="dm-toast" id="dmToast"></div>

<!-- Confirmation Modal -->
<div class="modal fade" id="dmConfirmModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-sm" role="document">
        <div class="modal-content" style="background:var(--bg2);border:1px solid var(--border);border-radius:12px">
            <div class="modal-header" style="border-bottom:1px solid var(--border);padding:14px 18px">
                <h4 class="modal-title" style="font-size:14px;font-weight:700;color:var(--t1);font-family:var(--font-b)" id="dmConfirmTitle">Confirm</h4>
                <button type="button" class="close" data-dismiss="modal" style="color:var(--t3);opacity:.7;font-size:18px">&times;</button>
            </div>
            <div class="modal-body" style="padding:16px 18px;font-size:13px;color:var(--t2);font-family:var(--font-b)" id="dmConfirmBody">
                Are you sure?
            </div>
            <div class="modal-footer" style="border-top:1px solid var(--border);padding:12px 18px;display:flex;gap:8px;justify-content:flex-end">
                <button class="dm-btn dm-btn-sm dm-btn-s" data-dismiss="modal">Cancel</button>
                <button class="dm-btn dm-btn-sm dm-btn-d" id="dmConfirmBtn" onclick="DM._confirmAction()">Confirm</button>
            </div>
        </div>
    </div>
</div>

<!-- Chart.js (loaded once) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

<script>
document.addEventListener('DOMContentLoaded', function(){
'use strict';

var B     = <?= json_encode(base_url()) ?>;
var DM    = window.DM = {};
var _data = {};
var _selectedUser = null;
var _bulkDT = null;
var _confirmCb = null;
var _distroChart = null;
var _platformChart = null;

/* ── Helpers ──────────────────────────────────────────────────── */
function esc(s){ return $('<div>').text(s||'').html(); }
function ts(s){ if(!s)return'-'; return (s+'').substring(0,19).replace('T',' '); }

function csrf(extra){
    var o = {};
    var n = $('meta[name="csrf-name"]').attr('content');
    var t = $('meta[name="csrf-token"]').attr('content');
    if(n && t) o[n] = t;
    return $.extend(o, extra||{});
}

function updateCsrf(resp){
    if(resp && resp.csrf_token){
        $('meta[name="csrf-token"]').attr('content', resp.csrf_token);
    }
}

function post(url, data, ok, fail){
    $.ajax({
        url: B + url,
        type: 'POST',
        data: csrf(data),
        dataType: 'json',
        success: function(r){
            updateCsrf(r);
            if(r.status==='success'){ if(ok) ok(r); }
            else { DM.toast(r.message||'An error occurred',false); if(fail) fail(r); }
        },
        error: function(xhr){
            var r = xhr.responseJSON||{};
            updateCsrf(r);
            DM.toast(r.message||'Request failed ('+xhr.status+')',false);
            if(fail) fail(r);
        }
    });
}

DM.toast = function(msg, ok){
    var t = $('#dmToast');
    t.text(msg).attr('class','dm-toast '+(ok?'success':'error'));
    setTimeout(function(){ t.attr('class','dm-toast'); }, 3500);
};

function statusBadge(s){
    s = (s||'active').toLowerCase();
    if(s==='blocked') return '<span class="dm-badge dm-b-rose">Blocked</span>';
    if(s==='has_blocked') return '<span class="dm-badge dm-b-amber">Has Blocked</span>';
    if(s==='over_limit') return '<span class="dm-badge dm-b-rose">Over Limit</span>';
    if(s==='ok') return '<span class="dm-badge dm-b-green">OK</span>';
    return '<span class="dm-badge dm-b-green">Active</span>';
}

function roleBadge(r){
    r = r||'';
    if(r==='Teacher') return '<span class="dm-badge dm-b-teal">Teacher</span>';
    if(r==='Student') return '<span class="dm-badge dm-b-blue">Student</span>';
    return '<span class="dm-badge dm-b-gray">'+esc(r)+'</span>';
}

function platformIcon(p){
    p = (p||'').toLowerCase();
    if(p.indexOf('android')>=0) return 'android';
    if(p.indexOf('ios')>=0 || p.indexOf('iphone')>=0) return 'ios';
    return 'other';
}

function platformIconHtml(p){
    var cls = platformIcon(p);
    var ico = cls==='android'?'fa-android':(cls==='ios'?'fa-apple':'fa-mobile');
    return '<div class="dm-device-icon '+cls+'"><i class="fa '+ico+'"></i></div>';
}

/* ── Tab switching ──────────────────────────────────────────── */
$('.dm-tab').on('click', function(){
    $('.dm-tab').removeClass('active');
    $(this).addClass('active');
    var t = $(this).attr('data-tab');
    $('.dm-pane').removeClass('active');
    $('#pane-'+t).addClass('active');

    // Lazy load tab data
    if(t==='dashboard' && !_data.dashLoaded) DM.loadDashboard();
    if(t==='bulk' && !_data.bulkLoaded) DM.loadBulk();
    if(t==='security' && !_data.secLoaded) DM.loadSecurityAlerts();
});

DM.refreshCurrent = function(){
    var active = $('.dm-tab.active').attr('data-tab');
    if(active==='dashboard'){ _data.dashLoaded=false; DM.loadDashboard(); }
    else if(active==='user' && _selectedUser){ DM.loadDevicesForUser(_selectedUser.userId); }
    else if(active==='bulk'){ _data.bulkLoaded=false; DM.loadBulk(); }
    else if(active==='security'){ _data.secLoaded=false; DM.loadSecurityAlerts(); }
};

/* ── Search with Enter key ──────────────────────────────────── */
$('#userSearchInput').on('keypress', function(e){ if(e.which===13) DM.searchUser(); });

/* ══════════════════════════════════════════════════════════════════
   TAB 1: DASHBOARD
   ══════════════════════════════════════════════════════════════════ */

DM.loadDashboard = function(){
    post('device_management/get_overview', {}, function(r){
        _data.dashLoaded = true;

        $('#kpi-users-devices').text(r.totalUsersWithDevices||0);
        $('#kpi-total-bound').text(r.totalBound||0);
        $('#kpi-blocked').text(r.totalBlocked||0);
        $('#kpi-avg').text(r.avgPerUser||0);

        // Distribution bar chart
        var dist = r.distribution||{};
        renderDistroChart(dist);

        // Platform pie chart
        var plat = r.platforms||{};
        renderPlatformChart(plat);

        // Timeline
        renderTimeline(r.recentActivity||[]);
    });
};

function renderDistroChart(dist){
    var ctx = document.getElementById('chartDistro');
    if(!ctx) return;
    if(_distroChart){ _distroChart.destroy(); _distroChart=null; }

    _distroChart = new Chart(ctx.getContext('2d'), {
        type: 'bar',
        data: {
            labels: ['1 Device', '2 Devices', '3+ Devices'],
            datasets: [{
                label: 'Users',
                data: [dist['1']||0, dist['2']||0, dist['3+']||0],
                backgroundColor: ['rgba(15,118,110,.6)', 'rgba(59,130,246,.6)', 'rgba(220,38,38,.5)'],
                borderRadius: 6,
                borderSkipped: false
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins:{legend:{display:false}},
            scales:{
                y:{beginAtZero:true, ticks:{stepSize:1, color:'var(--t3)',font:{family:'Plus Jakarta Sans',size:11}}, grid:{color:'rgba(15,118,110,.08)'}},
                x:{ticks:{color:'var(--t3)',font:{family:'Plus Jakarta Sans',size:11}}, grid:{display:false}}
            }
        }
    });
}

function renderPlatformChart(plat){
    var ctx = document.getElementById('chartPlatform');
    if(!ctx) return;
    if(_platformChart){ _platformChart.destroy(); _platformChart=null; }

    var labels = [], data = [], colors = [];
    if((plat['Android']||0)>0){ labels.push('Android'); data.push(plat['Android']); colors.push('rgba(22,163,74,.7)'); }
    if((plat['iOS']||0)>0){ labels.push('iOS'); data.push(plat['iOS']); colors.push('rgba(59,130,246,.7)'); }
    if((plat['Other']||0)>0){ labels.push('Other'); data.push(plat['Other']); colors.push('rgba(107,114,128,.5)'); }

    if(data.length===0){ labels=['No Data']; data=[1]; colors=['rgba(107,114,128,.2)']; }

    _platformChart = new Chart(ctx.getContext('2d'), {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors,
                borderWidth: 0,
                hoverOffset: 6
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '55%',
            plugins:{
                legend:{position:'bottom', labels:{color:'var(--t2)',font:{family:'Plus Jakarta Sans',size:11},padding:16}}
            }
        }
    });
}

function renderTimeline(items){
    var $tl = $('#dmTimeline');
    if(!items.length){
        $tl.html('<div class="dm-empty"><i class="fa fa-check-circle"></i>No recent device activity</div>');
        return;
    }
    var html = '';
    items.forEach(function(a){
        html += '<div class="dm-tl-item">'
            + '<div class="dm-tl-dot"></div>'
            + '<div class="dm-tl-content">'
            + '<div class="dm-tl-text"><strong>'+esc(a.userName)+'</strong> '+esc(a.action)+' device <strong>'+esc(a.deviceName)+'</strong></div>'
            + '<div class="dm-tl-time">'+ts(a.time)+'</div>'
            + '</div></div>';
    });
    $tl.html(html);
}

/* ══════════════════════════════════════════════════════════════════
   TAB 2: USER DEVICES
   ══════════════════════════════════════════════════════════════════ */

DM.searchUser = function(){
    var q = $.trim($('#userSearchInput').val());
    if(!q){ DM.toast('Enter a user ID or name to search', false); return; }

    $('#btnSearchUser').prop('disabled',true).html('<span class="dm-spinner"></span> Searching...');

    post('device_management/search_user', {query:q}, function(r){
        $('#btnSearchUser').prop('disabled',false).html('<i class="fa fa-search"></i> Search');
        var users = r.users||[];
        if(!users.length){
            $('#searchResults').show();
            $('#searchResultsTbody').html('<tr><td colspan="6" class="dm-empty"><i class="fa fa-search"></i>No users found</td></tr>');
            return;
        }
        var html = '';
        users.forEach(function(u){
            html += '<tr>'
                + '<td>'+esc(u.userId)+'</td>'
                + '<td>'+esc(u.name)+'</td>'
                + '<td>'+roleBadge(u.role)+'</td>'
                + '<td>'+esc(u.deviceCount)+'</td>'
                + '<td>'+statusBadge(u.status)+'</td>'
                + '<td><button class="dm-btn dm-btn-sm dm-btn-p" onclick="DM.selectUser(\''+esc(u.userId)+'\',\''+esc(u.name).replace(/'/g,"\\'")+'\',\''+esc(u.role)+'\',\''+esc(u.email||'')+'\',\''+esc(u.phone||'')+'\')"><i class="fa fa-eye"></i> View</button></td>'
                + '</tr>';
        });
        $('#searchResultsTbody').html(html);
        $('#searchResults').show();
    }, function(){
        $('#btnSearchUser').prop('disabled',false).html('<i class="fa fa-search"></i> Search');
    });
};

DM.selectUser = function(userId, name, role, email, phone){
    _selectedUser = {userId:userId, name:name, role:role, email:email, phone:phone};

    var initial = (name||userId).charAt(0).toUpperCase();
    $('#selectedUserCard').html(
        '<div class="dm-user-avatar">'+esc(initial)+'</div>'
        + '<div class="dm-user-info">'
        + '<div class="dm-user-name">'+esc(name)+' <span style="font-weight:400;font-size:12px;color:var(--t3)">('+esc(userId)+')</span></div>'
        + '<div class="dm-user-meta">'+roleBadge(role)+' &nbsp; '+esc(email)+(phone?' &middot; '+esc(phone):'')+'</div>'
        + '</div>'
    );
    $('#selectedUserSection').show();
    DM.loadDevicesForUser(userId);
};

DM.loadDevicesForUser = function(userId){
    var $list = $('#deviceList');
    $list.html('<div class="dm-empty"><span class="dm-spinner"></span> Loading devices...</div>');

    post('device_management/list_devices', {user_id:userId}, function(r){
        var devices = r.devices||[];
        $('#deviceCountBadge').text(devices.length+' device'+(devices.length!==1?'s':''));
        $('#btnBulkRemove').toggle(devices.length > 0);

        if(!devices.length){
            $list.html('<div class="dm-empty"><i class="fa fa-mobile"></i>No devices bound to this user</div>');
            return;
        }

        var html = '';
        devices.forEach(function(d){
            var isBlocked = (d.status||'').toLowerCase()==='blocked';
            var pCls = platformIcon(d.platform);
            var pIco = pCls==='android'?'fa-android':(pCls==='ios'?'fa-apple':'fa-mobile');

            html += '<div class="dm-device-card'+(isBlocked?' blocked':'')+'">'
                + '<div class="dm-device-header">'
                + '<div class="dm-device-icon '+pCls+'"><i class="fa '+pIco+'"></i></div>'
                + '<div><div class="dm-device-title">'+esc(d.deviceName||'Unknown Device')+'</div>'
                + statusBadge(d.status)
                + '</div></div>'
                + '<div class="dm-device-detail"><i class="fa fa-hashtag"></i> '+esc(d.deviceId||'-')+'</div>'
                + '<div class="dm-device-detail"><i class="fa fa-cog"></i> '+esc(d.platform||'-')+' '+esc(d.os||'')+'</div>'
                + '<div class="dm-device-detail"><i class="fa fa-code"></i> App '+esc(d.appVersion||'-')+'</div>'
                + '<div class="dm-device-detail"><i class="fa fa-calendar"></i> Bound: '+ts(d.boundAt)+'</div>'
                + '<div class="dm-device-detail"><i class="fa fa-clock-o"></i> Last used: '+ts(d.lastUsedAt)+'</div>'
                + '<div class="dm-device-actions">';

            if(isBlocked){
                html += '<button class="dm-btn dm-btn-sm dm-btn-p" onclick="DM.confirmUnblock(\''+esc(userId)+'\',\''+esc(d.deviceId)+'\',\''+esc((d.deviceName||'').replace(/'/g,"\\'")+'\',\''+esc(d.platform||'')+'\',\''+esc(d.os||'')+'\',\''+esc(d.appVersion||''))+'\')">'
                    + '<i class="fa fa-unlock"></i> Unblock</button>';
            } else {
                html += '<button class="dm-btn dm-btn-sm dm-btn-d" onclick="DM.confirmBlock(\''+esc(userId)+'\',\''+esc(d.deviceId)+'\',\''+esc((d.deviceName||'').replace(/'/g,"\\'"))+'\')">'
                    + '<i class="fa fa-ban"></i> Block</button>';
            }
            html += '<button class="dm-btn dm-btn-sm dm-btn-s" onclick="DM.confirmRemove(\''+esc(userId)+'\',\''+esc(d.deviceId)+'\',\''+esc((d.deviceName||'').replace(/'/g,"\\'"))+'\')">'
                + '<i class="fa fa-trash"></i> Remove</button>';
            html += '</div></div>';
        });
        $list.html(html);
    });
};

/* ── Confirm dialogs ──────────────────────────────────────── */
DM.confirm = function(title, body, cb){
    _confirmCb = cb;
    $('#dmConfirmTitle').text(title);
    $('#dmConfirmBody').html(body);
    $('#dmConfirmModal').modal('show');
};
DM._confirmAction = function(){
    $('#dmConfirmModal').modal('hide');
    if(_confirmCb) _confirmCb();
    _confirmCb = null;
};

DM.confirmRemove = function(userId, deviceId, deviceName){
    DM.confirm('Remove Device',
        'Remove <strong>'+esc(deviceName)+'</strong> from user <strong>'+esc(userId)+'</strong>?<br><small style="color:var(--t3)">The user will need to re-bind this device to use the app.</small>',
        function(){ DM.removeDevice(userId, deviceId); }
    );
};

DM.confirmBlock = function(userId, deviceId, deviceName){
    DM.confirm('Block Device',
        'Block <strong>'+esc(deviceName)+'</strong> for user <strong>'+esc(userId)+'</strong>?<br><small style="color:var(--t3)">The device will be unable to access the app until unblocked.</small>',
        function(){ DM.blockDevice(userId, deviceId); }
    );
};

DM.confirmUnblock = function(userId, deviceId, deviceName, platform, os, appVersion){
    DM.confirm('Unblock Device',
        'Unblock <strong>'+esc(deviceName)+'</strong> for user <strong>'+esc(userId)+'</strong>?',
        function(){ DM.unblockDevice(userId, deviceId, deviceName, platform, os, appVersion); }
    );
};

DM.removeDevice = function(userId, deviceId){
    post('device_management/remove_device', {user_id:userId, device_id:deviceId}, function(r){
        DM.toast(r.message||'Device removed', true);
        DM.loadDevicesForUser(userId);
    });
};

DM.blockDevice = function(userId, deviceId){
    post('device_management/block_device', {user_id:userId, device_id:deviceId}, function(r){
        DM.toast(r.message||'Device blocked', true);
        DM.loadDevicesForUser(userId);
    });
};

DM.unblockDevice = function(userId, deviceId, deviceName, platform, os, appVersion){
    post('device_management/unblock_device', {
        user_id:userId, device_id:deviceId, device_name:deviceName,
        platform:platform, os:os, app_version:appVersion
    }, function(r){
        DM.toast(r.message||'Device unblocked', true);
        DM.loadDevicesForUser(userId);
    });
};

/* ── Bulk Remove All Devices ─────────────────────────────── */
DM.confirmBulkRemove = function(){
    if(!_selectedUser) return;
    DM.confirm('Remove All Devices',
        'Remove <strong>all</strong> devices from user <strong>'+esc(_selectedUser.name)+'</strong> ('+esc(_selectedUser.userId)+')?<br><small style="color:var(--t3)">This will unbind every device. The user will need to re-bind to use the app.</small>',
        function(){ DM.bulkRemove(_selectedUser.userId); }
    );
};

DM.bulkRemove = function(userId){
    post('device_management/bulk_remove', {user_id:userId}, function(r){
        DM.toast(r.message||'All devices removed', true);
        DM.loadDevicesForUser(userId);
    });
};

/* ══════════════════════════════════════════════════════════════════
   TAB 3: BULK OVERVIEW (DataTable)
   ══════════════════════════════════════════════════════════════════ */

DM.loadBulk = function(){
    if(_bulkDT){ _bulkDT.destroy(); _bulkDT=null; }
    $('#bulkTable tbody').html('<tr><td colspan="8" style="text-align:center;padding:24px"><span class="dm-spinner"></span> Loading all users...</td></tr>');

    post('device_management/get_all_users_devices', {}, function(r){
        _data.bulkLoaded = true;
        _data.bulkRaw = r.users||[];
        renderBulkTable(_data.bulkRaw);
    });
};

function renderBulkTable(users){
    if(_bulkDT){ _bulkDT.destroy(); _bulkDT=null; }

    // Apply client-side filters
    var fRole   = $('#bulkFilterRole').val();
    var fMin    = parseInt($('#bulkFilterMin').val())||0;
    var fStatus = $('#bulkFilterStatus').val();

    var filtered = users.filter(function(u){
        if(fRole && u.role!==fRole) return false;
        if(fMin && u.deviceCount<fMin) return false;
        if(fStatus && u.status!==fStatus) return false;
        return true;
    });

    var rows = [];
    filtered.forEach(function(u, i){
        rows.push([
            '<button class="dm-btn dm-btn-sm dm-btn-s dm-expand-btn" data-idx="'+i+'"><i class="fa fa-plus"></i></button>',
            esc(u.userId),
            esc(u.name),
            u.role,
            u.deviceCount,
            u.blockedCount,
            u.status,
            '<button class="dm-btn dm-btn-sm dm-btn-p" onclick="DM.bulkViewUser(\''+esc(u.userId)+'\',\''+esc(u.name).replace(/'/g,"\\'")+'\',\''+esc(u.role)+'\')"><i class="fa fa-eye"></i></button>'
        ]);
    });

    _bulkDT = new DataTable('#bulkTable', {
        data: rows,
        destroy: true,
        autoWidth: false,
        pageLength: 25,
        order: [[4, 'desc']],
        columnDefs: [
            {targets:0, orderable:false, width:'40px'},
            {targets:3, render:function(d){return roleBadge(d);}},
            {targets:6, render:function(d){return statusBadge(d);}},
            {targets:7, orderable:false, width:'60px'}
        ],
        layout: {
            topStart: {
                buttons: [
                    {extend:'csvHtml5', title:'Device_Management_Export', className:'dm-btn dm-btn-sm dm-btn-s'},
                    {extend:'excelHtml5', title:'Device_Management_Export', className:'dm-btn dm-btn-sm dm-btn-s'},
                    {extend:'print', className:'dm-btn dm-btn-sm dm-btn-s'}
                ]
            }
        },
        language: {emptyTable: 'No users found matching criteria'}
    });

    // Expand row handler
    _data.bulkFiltered = filtered;
    $('#bulkTable tbody').off('click', '.dm-expand-btn').on('click', '.dm-expand-btn', function(){
        var tr = $(this).closest('tr');
        var row = _bulkDT.row(tr);
        var idx = parseInt($(this).data('idx'));

        if(row.child.isShown()){
            row.child.hide();
            $(this).html('<i class="fa fa-plus"></i>');
        } else {
            var u = _data.bulkFiltered[idx];
            var html = buildExpandHtml(u);
            row.child(html).show();
            $(this).html('<i class="fa fa-minus"></i>');
        }
    });
}

function buildExpandHtml(u){
    if(!u || !u.devices || !u.devices.length){
        return '<div class="dm-expand-inner" style="color:var(--t3);font-size:12px;font-family:var(--font-b)">No devices</div>';
    }
    var html = '<div class="dm-expand-inner"><table class="dm-table" style="margin:0"><thead><tr>'
        + '<th>Device ID</th><th>Name</th><th>Platform</th><th>OS</th><th>Status</th><th>Bound At</th><th>Last Used</th><th>App Ver</th>'
        + '</tr></thead><tbody>';
    u.devices.forEach(function(d){
        html += '<tr>'
            + '<td style="font-family:var(--font-m);font-size:11px">'+esc(d.deviceId)+'</td>'
            + '<td>'+esc(d.deviceName)+'</td>'
            + '<td>'+esc(d.platform)+'</td>'
            + '<td>'+esc(d.os)+'</td>'
            + '<td>'+statusBadge(d.status)+'</td>'
            + '<td>'+ts(d.boundAt)+'</td>'
            + '<td>'+ts(d.lastUsedAt)+'</td>'
            + '<td>'+esc(d.appVersion)+'</td>'
            + '</tr>';
    });
    html += '</tbody></table></div>';
    return html;
}

// Re-filter when filter controls change
$('#bulkFilterRole, #bulkFilterMin, #bulkFilterStatus').on('change input', function(){
    if(_data.bulkRaw) renderBulkTable(_data.bulkRaw);
});

DM.bulkViewUser = function(userId, name, role){
    // Switch to User Devices tab and select user
    $('.dm-tab').removeClass('active');
    $('.dm-tab[data-tab="user"]').addClass('active');
    $('.dm-pane').removeClass('active');
    $('#pane-user').addClass('active');

    DM.selectUser(userId, name, role, '', '');
};

/* ══════════════════════════════════════════════════════════════════
   TAB 4: SECURITY ALERTS
   ══════════════════════════════════════════════════════════════════ */

DM.loadSecurityAlerts = function(){
    // Show spinners
    ['secBlockedList','secOverLimitList','secStaleList','secRapidList'].forEach(function(id){
        $('#'+id).html('<div class="dm-empty"><span class="dm-spinner"></span> Analyzing...</div>');
    });

    post('device_management/get_suspicious_activity', {}, function(r){
        _data.secLoaded = true;
        var a = r.alerts||{};
        renderSecurityAlerts(a);
    });
};

function renderSecurityAlerts(a){
    // Blocked
    var blocked = a.blocked_devices||[];
    $('#sec-kpi-blocked').text(blocked.length);
    $('#secBlockedCount').text(blocked.length);
    if(!blocked.length){
        $('#secBlockedList').html('<div class="dm-empty"><i class="fa fa-check-circle" style="color:#16a34a"></i>No blocked devices found</div>');
    } else {
        var html = '';
        blocked.forEach(function(u){
            u.devices.forEach(function(d){
                html += '<div class="dm-alert-item">'
                    + '<div><strong>'+esc(u.name)+'</strong> <span style="color:var(--t3);font-size:11px">('+esc(u.userId)+')</span> '+roleBadge(u.role)
                    + ' &mdash; '+esc(d.deviceName)+' <span style="font-family:var(--font-m);font-size:10px;color:var(--t4)">'+esc(d.deviceId)+'</span></div>'
                    + '<button class="dm-btn dm-btn-sm dm-btn-p" onclick="DM.bulkViewUser(\''+esc(u.userId)+'\',\''+esc(u.name).replace(/'/g,"\\'")+'\',\''+esc(u.role)+'\')"><i class="fa fa-eye"></i></button>'
                    + '</div>';
            });
        });
        $('#secBlockedList').html(html);
    }

    // Over limit
    var over = a.over_limit||[];
    $('#sec-kpi-overlimit').text(over.length);
    $('#secOverLimitCount').text(over.length);
    if(!over.length){
        $('#secOverLimitList').html('<div class="dm-empty"><i class="fa fa-check-circle" style="color:#16a34a"></i>All users within device limit</div>');
    } else {
        var html2 = '';
        over.forEach(function(u){
            html2 += '<div class="dm-alert-item">'
                + '<div><strong>'+esc(u.name)+'</strong> <span style="color:var(--t3);font-size:11px">('+esc(u.userId)+')</span> '+roleBadge(u.role)
                + ' &mdash; <span class="dm-badge dm-b-rose">'+u.activeCount+' devices</span> (limit: '+u.limit+')</div>'
                + '<button class="dm-btn dm-btn-sm dm-btn-p" onclick="DM.bulkViewUser(\''+esc(u.userId)+'\',\''+esc(u.name).replace(/'/g,"\\'")+'\',\''+esc(u.role)+'\')"><i class="fa fa-eye"></i></button>'
                + '</div>';
        });
        $('#secOverLimitList').html(html2);
    }

    // Stale
    var stale = a.stale_devices||[];
    $('#sec-kpi-stale').text(stale.length);
    $('#secStaleCount').text(stale.length);
    if(!stale.length){
        $('#secStaleList').html('<div class="dm-empty"><i class="fa fa-check-circle" style="color:#16a34a"></i>No stale devices</div>');
    } else {
        var html3 = '';
        stale.forEach(function(u){
            u.devices.forEach(function(d){
                html3 += '<div class="dm-alert-item">'
                    + '<div><strong>'+esc(u.name)+'</strong> <span style="color:var(--t3);font-size:11px">('+esc(u.userId)+')</span> '+roleBadge(u.role)
                    + ' &mdash; '+esc(d.deviceName)+' <span class="dm-badge dm-b-amber">'+d.daysIdle+' days idle</span></div>'
                    + '<button class="dm-btn dm-btn-sm dm-btn-p" onclick="DM.bulkViewUser(\''+esc(u.userId)+'\',\''+esc(u.name).replace(/'/g,"\\'")+'\',\''+esc(u.role)+'\')"><i class="fa fa-eye"></i></button>'
                    + '</div>';
            });
        });
        $('#secStaleList').html(html3);
    }

    // Rapid binding
    var rapid = a.rapid_binding||[];
    $('#sec-kpi-rapid').text(rapid.length);
    $('#secRapidCount').text(rapid.length);
    if(!rapid.length){
        $('#secRapidList').html('<div class="dm-empty"><i class="fa fa-check-circle" style="color:#16a34a"></i>No suspicious rapid binding detected</div>');
    } else {
        var html4 = '';
        rapid.forEach(function(u){
            html4 += '<div class="dm-alert-item">'
                + '<div><strong>'+esc(u.name)+'</strong> <span style="color:var(--t3);font-size:11px">('+esc(u.userId)+')</span> '+roleBadge(u.role)
                + ' &mdash; <span class="dm-badge dm-b-purple">'+u.devicesBound+' devices</span> bound within '+u.windowHours+'h</div>'
                + '<button class="dm-btn dm-btn-sm dm-btn-p" onclick="DM.bulkViewUser(\''+esc(u.userId)+'\',\''+esc(u.name).replace(/'/g,"\\'")+'\',\''+esc(u.role)+'\')"><i class="fa fa-eye"></i></button>'
                + '</div>';
        });
        $('#secRapidList').html(html4);
    }
}

/* ══════════════════════════════════════════════════════════════════
   INITIAL LOAD
   ══════════════════════════════════════════════════════════════════ */
DM.loadDashboard();

}); // DOMContentLoaded
</script>
