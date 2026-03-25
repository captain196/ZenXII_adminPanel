<?php
$today               = $today               ?? date('Y-m-d');
$activity_count      = $activity_count      ?? 0;
$error_count         = $error_count         ?? 0;
$api_count           = $api_count           ?? 0;
$school_login_count  = $school_login_count  ?? 0;
?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.2/dist/chart.umd.min.js"></script>

<section class="content-header">
    <h1><i class="fa fa-heartbeat" style="color:var(--sa3);margin-right:10px;font-size:20px;"></i>System Monitor</h1>
    <ol class="breadcrumb">
        <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
        <li class="active">Monitor</li>
    </ol>
</section>

<section class="content" style="padding:20px 24px;">

    <!-- ── KPI Row ── -->
    <div class="row" style="margin-bottom:20px;">

        <!-- System Health -->
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:14px;">
            <div id="healthKpi" style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:16px 14px;cursor:pointer;transition:all .2s;" onclick="checkHealth()">
                <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;">System Health</div>
                <div id="healthKpiVal" style="font-size:22px;font-weight:800;color:var(--t1);font-family:var(--font-d);">—</div>
                <div style="margin-top:6px;font-size:10px;color:var(--t4);"><i class="fa fa-server" style="color:#8b5cf6;margin-right:3px;"></i> Click to check</div>
            </div>
        </div>

        <!-- SA Actions -->
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:14px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:16px 14px;">
                <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;">SA Actions</div>
                <div style="font-size:22px;font-weight:800;color:var(--t1);font-family:var(--font-d);"><?= $activity_count ?></div>
                <div style="margin-top:6px;font-size:10px;color:var(--t4);"><i class="fa fa-bolt" style="color:#22c55e;margin-right:3px;"></i> Today</div>
            </div>
        </div>

        <!-- School Logins -->
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:14px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:16px 14px;">
                <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;">School Logins</div>
                <div style="font-size:22px;font-weight:800;color:var(--t1);font-family:var(--font-d);"><?= $school_login_count ?></div>
                <div style="margin-top:6px;font-size:10px;color:var(--t4);"><i class="fa fa-sign-in" style="color:#3b82f6;margin-right:3px;"></i> Today</div>
            </div>
        </div>

        <!-- API Calls -->
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:14px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:16px 14px;">
                <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;">API Calls</div>
                <div style="font-size:22px;font-weight:800;color:var(--t1);font-family:var(--font-d);"><?= $api_count ?></div>
                <div style="margin-top:6px;font-size:10px;color:var(--t4);"><i class="fa fa-exchange" style="color:#14b8a6;margin-right:3px;"></i> Tracked today</div>
            </div>
        </div>

        <!-- Errors -->
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:14px;">
            <div style="background:var(--bg2);border:1px solid <?= $error_count > 0 ? 'rgba(239,68,68,.35)' : 'var(--border)' ?>;border-radius:12px;padding:16px 14px;">
                <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;">Errors</div>
                <div style="font-size:22px;font-weight:800;color:<?= $error_count > 0 ? '#ef4444' : 'var(--t1)' ?>;font-family:var(--font-d);"><?= $error_count ?></div>
                <div style="margin-top:6px;font-size:10px;color:var(--t4);"><i class="fa fa-exclamation-triangle" style="color:<?= $error_count > 0 ? '#ef4444' : 'var(--t4)' ?>;margin-right:3px;"></i> Today</div>
            </div>
        </div>

        <!-- Firebase -->
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:14px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:16px 14px;">
                <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;">Firebase</div>
                <div id="fbKpiStatus" style="font-size:22px;font-weight:800;color:var(--t3);font-family:var(--font-d);">—</div>
                <div id="fbKpiMs" style="margin-top:6px;font-size:10px;color:var(--t4);"><i class="fa fa-database" style="color:#f59e0b;margin-right:3px;"></i> Response time</div>
            </div>
        </div>

    </div><!-- /.row KPIs -->

    <!-- ── Nav Tabs ── -->
    <ul class="nav nav-tabs" id="monitorTabs" style="border-color:var(--border);margin-bottom:0;">
        <li class="active"><a href="#tab-overview"  data-toggle="tab"><i class="fa fa-dashboard" style="margin-right:5px;"></i>Overview</a></li>
        <li><a href="#tab-logins"    data-toggle="tab"><i class="fa fa-sign-in"             style="margin-right:5px;"></i>Login Logs</a></li>
        <li><a href="#tab-api"       data-toggle="tab"><i class="fa fa-exchange"             style="margin-right:5px;"></i>API Usage</a></li>
        <li><a href="#tab-errors"    data-toggle="tab"><i class="fa fa-exclamation-triangle" style="margin-right:5px;"></i>Error Logs</a></li>
        <li><a href="#tab-health"    data-toggle="tab"><i class="fa fa-cog"                  style="margin-right:5px;"></i>Server Health</a></li>
    </ul>

    <style>
    .nav-tabs>li>a { color:var(--t3)!important; border-color:transparent!important; background:var(--bg2)!important; font-size:12.5px!important; }
    .nav-tabs>li.active>a,.nav-tabs>li>a:hover { color:var(--t1)!important; border-color:var(--border) var(--border) var(--bg2)!important; }
    .nav-tabs>li.active>a { color:var(--sa3)!important; border-top:2px solid var(--sa3)!important; }
    .tab-content { background:var(--bg2); border:1px solid var(--border); border-top:none; border-radius:0 0 var(--r) var(--r); padding:20px; }
    .mon-section-head { font-size:11px; font-weight:700; color:var(--t3); text-transform:uppercase; letter-spacing:.8px; font-family:var(--font-m); margin-bottom:12px; padding-bottom:8px; border-bottom:1px solid var(--border); }
    .gauge-bar { background:var(--bg3); border-radius:20px; height:8px; overflow:hidden; margin-top:4px; }
    .gauge-fill { height:100%; border-radius:20px; transition:width .6s ease; }
    .svc-badge { display:inline-flex; align-items:center; gap:5px; padding:5px 10px; border-radius:8px; font-size:11.5px; font-family:var(--font-m); font-weight:600; }
    .log-row-error   td { color:#ef4444!important; }
    .log-row-warning td { color:#f59e0b!important; }
    </style>

    <div class="tab-content">

        <!-- ════════════════════════════════════════════════════════════ OVERVIEW -->
        <div class="tab-pane active" id="tab-overview">

            <div class="row">
                <!-- 7-day activity stacked bar chart -->
                <div class="col-md-8" style="margin-bottom:20px;">
                    <div class="mon-section-head"><i class="fa fa-bar-chart" style="margin-right:5px;color:var(--sa3);"></i>7-Day Firebase Operations</div>
                    <div style="position:relative;height:240px;">
                        <canvas id="usageChart"></canvas>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:10px;">
                        <span style="font-size:11px;color:var(--t3);font-family:var(--font-m);display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:2px;background:#8b5cf6;display:inline-block;"></span>SA Activity</span>
                        <span style="font-size:11px;color:var(--t3);font-family:var(--font-m);display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:2px;background:#3b82f6;display:inline-block;"></span>School Logins</span>
                        <span style="font-size:11px;color:var(--t3);font-family:var(--font-m);display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:2px;background:#14b8a6;display:inline-block;"></span>API Calls</span>
                        <span style="font-size:11px;color:var(--t3);font-family:var(--font-m);display:flex;align-items:center;gap:4px;"><span style="width:10px;height:10px;border-radius:2px;background:#ef4444;display:inline-block;"></span>Errors</span>
                    </div>
                </div>

                <!-- Today summary -->
                <div class="col-md-4" style="margin-bottom:20px;">
                    <div class="mon-section-head"><i class="fa fa-info-circle" style="margin-right:5px;color:var(--sa3);"></i>Today at a Glance</div>
                    <div id="overviewSummary" style="font-size:12.5px;color:var(--t2);">
                        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
                            <span><i class="fa fa-bolt" style="color:#22c55e;margin-right:5px;width:14px;"></i>SA Actions</span>
                            <strong><?= $activity_count ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
                            <span><i class="fa fa-sign-in" style="color:#3b82f6;margin-right:5px;width:14px;"></i>School Logins</span>
                            <strong><?= $school_login_count ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
                            <span><i class="fa fa-exchange" style="color:#14b8a6;margin-right:5px;width:14px;"></i>API Calls</span>
                            <strong><?= $api_count ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
                            <span><i class="fa fa-exclamation-triangle" style="color:#ef4444;margin-right:5px;width:14px;"></i>Errors</span>
                            <strong style="color:<?= $error_count > 0 ? '#ef4444' : 'var(--t1)' ?>"><?= $error_count ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;padding:8px 0;border-bottom:1px solid var(--border);">
                            <span><i class="fa fa-database" style="color:#f59e0b;margin-right:5px;width:14px;"></i>Firebase</span>
                            <span id="overviewFbStatus" style="color:var(--t3);">—</span>
                        </div>
                        <div style="display:flex;justify-content:space-between;padding:8px 0;">
                            <span><i class="fa fa-hdd-o" style="color:#8b5cf6;margin-right:5px;width:14px;"></i>Disk Free</span>
                            <span id="overviewDisk" style="color:var(--t3);">—</span>
                        </div>
                    </div>
                    <button class="btn btn-default btn-sm" style="margin-top:12px;width:100%;" onclick="checkHealth()">
                        <i class="fa fa-refresh" id="healthBtnIcon"></i> Run Health Check
                    </button>
                </div>
            </div>

            <!-- Recent SA Activity (today) -->
            <div class="mon-section-head" style="margin-top:8px;"><i class="fa fa-history" style="margin-right:5px;color:var(--sa3);"></i>Latest SA Activity (Today)</div>
            <div style="overflow-x:auto;">
                <table class="table" style="margin:0;font-size:12.5px;">
                    <thead><tr>
                        <th style="width:80px;">Time</th>
                        <th>Action</th>
                        <th>SA User</th>
                        <th>School</th>
                        <th>IP</th>
                    </tr></thead>
                    <tbody id="overviewActivityBody">
                        <tr><td colspan="5" style="text-align:center;padding:20px;color:var(--t3);"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
                    </tbody>
                </table>
            </div>

        </div><!-- /tab-overview -->

        <!-- ════════════════════════════════════════════════════════════ LOGIN LOGS -->
        <div class="tab-pane" id="tab-logins">

            <!-- Controls -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px;">
                <select class="form-control" id="loginTypeSelect" style="height:34px;width:160px;font-size:12.5px;">
                    <option value="Activity">SA Activity</option>
                    <option value="SchoolLogins">School Admin Logins</option>
                    <option value="Logins">SA Logins</option>
                </select>
                <input type="date" class="form-control" id="loginDate" value="<?= $today ?>" style="height:34px;width:140px;font-size:12.5px;">
                <input type="text" class="form-control" id="loginSearch" placeholder="Filter by user / school / IP…" style="height:34px;width:200px;font-size:12.5px;">
                <button class="btn btn-primary btn-sm" id="loadLoginBtn"><i class="fa fa-search"></i> Load</button>
                <div style="margin-left:auto;">
                    <button class="btn btn-danger btn-sm" id="clearLoginLogsBtn"><i class="fa fa-trash"></i> Clear</button>
                </div>
            </div>
            <div style="font-size:11.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:10px;" id="loginLogsMeta">Select a log type and date, then click Load.</div>

            <div style="overflow-x:auto;">
                <table class="table" style="margin:0;font-size:12.5px;min-width:650px;">
                    <thead><tr>
                        <th style="width:75px;">Time</th>
                        <th>Action / Event</th>
                        <th>User</th>
                        <th>School</th>
                        <th style="width:105px;">IP</th>
                        <th style="width:80px;">Status</th>
                    </tr></thead>
                    <tbody id="loginLogsTbody">
                        <tr><td colspan="6" style="text-align:center;padding:28px;color:var(--t3);">No logs loaded.</td></tr>
                    </tbody>
                </table>
            </div>

        </div><!-- /tab-logins -->

        <!-- ════════════════════════════════════════════════════════════ API USAGE -->
        <div class="tab-pane" id="tab-api">

            <div class="row" style="margin-bottom:20px;">

                <!-- 7-day line chart -->
                <div class="col-md-8">
                    <div class="mon-section-head"><i class="fa fa-line-chart" style="margin-right:5px;color:var(--sa3);"></i>7-Day API Call Trend</div>
                    <div style="height:220px;position:relative;">
                        <canvas id="apiTrendChart"></canvas>
                    </div>
                </div>

                <!-- Firebase DB info panel -->
                <div class="col-md-4">
                    <div class="mon-section-head"><i class="fa fa-database" style="margin-right:5px;color:var(--sa3);"></i>Firebase DB Usage</div>
                    <div style="background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:14px;font-size:12px;color:var(--t2);">
                        <p style="margin-bottom:10px;">Firebase Realtime Database usage (bandwidth, storage, connections) is available in the
                            <a href="https://console.firebase.google.com" target="_blank" style="color:var(--sa3);">Firebase Console</a>.</p>
                        <div style="font-size:11px;color:var(--t3);margin-bottom:8px;font-family:var(--font-m);text-transform:uppercase;letter-spacing:.5px;">Tracked Here</div>
                        <div style="display:flex;flex-direction:column;gap:6px;">
                            <div style="display:flex;justify-content:space-between;">
                                <span><i class="fa fa-bolt" style="color:#8b5cf6;margin-right:5px;"></i>SA Actions today</span>
                                <strong><?= $activity_count ?></strong>
                            </div>
                            <div style="display:flex;justify-content:space-between;">
                                <span><i class="fa fa-exchange" style="color:#14b8a6;margin-right:5px;"></i>API calls today</span>
                                <strong><?= $api_count ?></strong>
                            </div>
                            <div style="display:flex;justify-content:space-between;">
                                <span><i class="fa fa-sign-in" style="color:#3b82f6;margin-right:5px;"></i>School logins today</span>
                                <strong><?= $school_login_count ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

            <!-- API Log table -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
                <div class="mon-section-head" style="margin:0;border:none;padding:0;flex:1;"><i class="fa fa-list" style="margin-right:5px;color:var(--sa3);"></i>API Call Log</div>
                <input type="date" class="form-control" id="apiDate" value="<?= $today ?>" style="height:34px;width:140px;font-size:12.5px;">
                <input type="text" class="form-control" id="apiSearch" placeholder="Filter endpoint…" style="height:34px;width:180px;font-size:12.5px;">
                <button class="btn btn-primary btn-sm" id="loadApiBtn"><i class="fa fa-search"></i> Load</button>
                <button class="btn btn-danger btn-sm" id="clearApiLogsBtn"><i class="fa fa-trash"></i> Clear</button>
            </div>
            <div style="font-size:11.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:8px;" id="apiLogsMeta"></div>

            <div style="overflow-x:auto;">
                <table class="table" style="margin:0;font-size:12px;min-width:700px;">
                    <thead><tr>
                        <th style="width:75px;">Time</th>
                        <th style="width:60px;">Method</th>
                        <th>Endpoint</th>
                        <th style="width:90px;">Duration</th>
                        <th>SA User</th>
                        <th style="width:70px;">IP</th>
                        <th style="width:75px;">Status</th>
                    </tr></thead>
                    <tbody id="apiLogsTbody">
                        <tr><td colspan="7" style="text-align:center;padding:28px;color:var(--t3);">Load a date to view API call logs.</td></tr>
                    </tbody>
                </table>
            </div>

        </div><!-- /tab-api -->

        <!-- ════════════════════════════════════════════════════════════ ERROR LOGS -->
        <div class="tab-pane" id="tab-errors">

            <!-- Severity filter + date -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px;">
                <div style="display:flex;gap:5px;flex-wrap:wrap;">
                    <button class="btn btn-default btn-sm err-filter active" data-level="all">All</button>
                    <button class="btn btn-danger btn-sm err-filter"  data-level="error"   style="opacity:.7;">Error</button>
                    <button class="btn btn-warning btn-sm err-filter" data-level="warning" style="opacity:.7;">Warning</button>
                    <button class="btn btn-info btn-sm err-filter"    data-level="info"    style="opacity:.7;">Info</button>
                </div>
                <input type="date" class="form-control" id="errorDate" value="<?= $today ?>" style="height:34px;width:140px;font-size:12.5px;margin-left:auto;">
                <button class="btn btn-primary btn-sm" id="loadErrorBtn"><i class="fa fa-search"></i> Load</button>
                <button class="btn btn-danger btn-sm" id="clearErrorLogsBtn"><i class="fa fa-trash"></i> Clear</button>
            </div>

            <!-- Severity count badges -->
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;" id="errorSeverityRow">
                <span class="label label-danger" style="font-size:11.5px;padding:4px 10px;">Error: <strong id="errCntError">0</strong></span>
                <span class="label label-warning" style="font-size:11.5px;padding:4px 10px;">Warning: <strong id="errCntWarning">0</strong></span>
                <span class="label label-info" style="font-size:11.5px;padding:4px 10px;">Info: <strong id="errCntInfo">0</strong></span>
                <span class="label label-default" style="font-size:11.5px;padding:4px 10px;">Total: <strong id="errCntTotal">0</strong></span>
            </div>

            <div style="font-size:11.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:8px;" id="errorLogsMeta"></div>

            <div style="overflow-x:auto;">
                <table class="table" style="margin:0;font-size:12.5px;min-width:680px;">
                    <thead><tr>
                        <th style="width:75px;">Time</th>
                        <th style="width:80px;">Level</th>
                        <th>Message</th>
                        <th>Context / File</th>
                        <th style="width:100px;">IP</th>
                    </tr></thead>
                    <tbody id="errorLogsTbody">
                        <tr><td colspan="5" style="text-align:center;padding:28px;color:var(--t3);">Load a date to view error logs.</td></tr>
                    </tbody>
                </table>
            </div>

        </div><!-- /tab-errors -->

        <!-- ════════════════════════════════════════════════════════════ SERVER HEALTH -->
        <div class="tab-pane" id="tab-health">

            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
                <div class="mon-section-head" style="margin:0;border:none;padding:0;"><i class="fa fa-cog" style="margin-right:5px;color:var(--sa3);"></i>Live Server Health</div>
                <div style="display:flex;gap:8px;align-items:center;">
                    <label style="font-size:11px;color:var(--t3);margin:0;text-transform:none !important;letter-spacing:0 !important;font-weight:normal !important;">
                        <input type="checkbox" id="autoRefreshToggle" style="margin-right:4px;"> Auto-refresh (30s)
                    </label>
                    <button class="btn btn-primary btn-sm" id="refreshHealthBtn"><i class="fa fa-refresh" id="healthRefreshIcon"></i> Refresh</button>
                </div>
            </div>

            <div id="healthContent">
                <div style="text-align:center;padding:40px;color:var(--t3);">
                    <i class="fa fa-spinner fa-spin" style="font-size:24px;display:block;margin-bottom:10px;"></i>
                    Loading health data...
                </div>
            </div>

        </div><!-- /tab-health -->

    </div><!-- /tab-content -->

</section>

<!-- Auto-Cleanup Old Logs Button -->
<div style="margin:0 0 20px;padding:14px 18px;background:var(--bg2);border:1px solid var(--border);border-radius:10px;display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
    <div style="flex:1;min-width:200px;">
        <div style="font-size:13px;font-weight:600;color:var(--t1);margin-bottom:2px;"><i class="fa fa-recycle" style="color:var(--amber);margin-right:6px;"></i>Auto-Cleanup Old Logs</div>
        <div style="font-size:12px;color:var(--t3);">Deletes all log entries older than the specified number of days across all log types.</div>
    </div>
    <div style="display:flex;align-items:center;gap:8px;">
        <label style="font-size:12px;color:var(--t2);margin:0;">Retain last</label>
        <input type="number" id="cleanupRetainDays" value="30" min="1" max="365"
               style="width:70px;padding:5px 8px;font-size:13px;background:var(--bg3);border:1px solid var(--border);border-radius:6px;color:var(--t1);">
        <label style="font-size:12px;color:var(--t2);margin:0;">days</label>
        <button class="btn btn-warning btn-sm" id="cleanupOldLogsBtn">
            <i class="fa fa-trash-o"></i> Run Cleanup
        </button>
        <span id="cleanupResult" style="font-size:12px;color:var(--t3);"></span>
    </div>
</div>

<!-- Clear Logs Modal -->
<div class="modal fade" id="clearLogsModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title"><i class="fa fa-trash" style="color:var(--rose);margin-right:8px;"></i>Clear Logs</h4>
            </div>
            <div class="modal-body">
                <p style="color:var(--t2);font-size:13px;">Permanently delete <strong id="clearLogsTypeLabel"></strong> logs for <strong id="clearLogsDateLabel"></strong>? This cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmClearLogsBtn"><i class="fa fa-trash"></i> Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
/* ── Helpers ── */
function esc(s){ return $('<div>').text(s||'').html(); }
function ts(s){ return (s||'').substring(11,19); }
function fmtMs(ms){ return ms < 1000 ? ms+'ms' : (ms/1000).toFixed(1)+'s'; }
function getTextColor(){ return getComputedStyle(document.documentElement).getPropertyValue('--t2').trim() || '#9ca3af'; }
function getGridColor(){ return getComputedStyle(document.documentElement).getPropertyValue('--border').trim() || 'rgba(255,255,255,.08)'; }

/* ── Chart instances ── */
var usageChart   = null;
var apiChart     = null;
var _allErrRows  = [];
var _autoTimer   = null;

/* ─────────────────────── USAGE CHART ─────────────────────── */
function buildUsageChart(days){
    var labels = days.map(function(d){ return d.date.substring(5); }); // MM-DD
    var ctx = document.getElementById('usageChart').getContext('2d');
    if(usageChart) usageChart.destroy();
    usageChart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [
                { label:'SA Activity',   data: days.map(function(d){ return d.activity; }),      backgroundColor:'rgba(139,92,246,.7)',  borderRadius:3 },
                { label:'School Logins', data: days.map(function(d){ return d.school_logins; }), backgroundColor:'rgba(59,130,246,.7)',   borderRadius:3 },
                { label:'API Calls',     data: days.map(function(d){ return d.api_calls; }),     backgroundColor:'rgba(20,184,166,.7)',   borderRadius:3 },
                { label:'Errors',        data: days.map(function(d){ return d.errors; }),        backgroundColor:'rgba(239,68,68,.7)',    borderRadius:3 },
            ]
        },
        options: {
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ display:false } },
            scales:{
                x:{ stacked:true, ticks:{ color:getTextColor(), font:{size:11} }, grid:{ color:getGridColor() } },
                y:{ stacked:true, ticks:{ color:getTextColor(), font:{size:11} }, grid:{ color:getGridColor() } }
            },
            animation:{ duration:500 }
        }
    });
}

/* ─────────────────────── API TREND CHART ─────────────────── */
function buildApiChart(days){
    var labels = days.map(function(d){ return d.date.substring(5); });
    var ctx = document.getElementById('apiTrendChart').getContext('2d');
    if(apiChart) apiChart.destroy();
    apiChart = new Chart(ctx, {
        type:'line',
        data:{
            labels: labels,
            datasets:[{
                label:'API Calls',
                data: days.map(function(d){ return d.api_calls; }),
                borderColor:'#14b8a6', backgroundColor:'rgba(20,184,166,.12)',
                borderWidth:2, tension:.35, fill:true, pointRadius:4,
            }]
        },
        options:{
            responsive:true, maintainAspectRatio:false,
            plugins:{ legend:{ display:false } },
            scales:{
                x:{ ticks:{ color:getTextColor(), font:{size:11} }, grid:{ color:getGridColor() } },
                y:{ ticks:{ color:getTextColor(), font:{size:11} }, grid:{ color:getGridColor() } }
            },
            animation:{ duration:500 }
        }
    });
}

/* ─────────────────────── FIREBASE USAGE (7-day) ─────────── */
function loadFirebaseUsage(){
    $.post(BASE_URL+'superadmin/monitor/firebase_usage', {}, function(r){
        if(r.status !== 'success') return;
        buildUsageChart(r.days || []);
        buildApiChart(r.days   || []);
    },'json');
}

/* ─────────────────────── OVERVIEW ACTIVITY ──────────────── */
function loadOverviewActivity(){
    $.post(BASE_URL+'superadmin/monitor/activity', { date: '<?= $today ?>' }, function(r){
        if(r.status !== 'success'){ $('#overviewActivityBody').html('<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--t3);">No activity today.</td></tr>'); return; }
        var rows = (r.rows || []).slice(0,15);
        if(!rows.length){
            $('#overviewActivityBody').html('<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--t3);">No SA activity today.</td></tr>'); return;
        }
        var html = rows.map(function(log){
            return '<tr>'
              +'<td style="font-family:var(--font-m);font-size:10.5px;color:var(--t3);">'+ts(log.timestamp)+'</td>'
              +'<td style="color:var(--sa3);font-family:var(--font-m);font-size:12px;">'+esc(log.action||'—')+'</td>'
              +'<td style="font-size:12px;">'+esc(log.sa_name||'—')+'</td>'
              +'<td style="font-size:11px;color:var(--t3);">'+esc(log.school_uid||'—')+'</td>'
              +'<td style="font-size:10.5px;color:var(--t4);">'+esc(log.ip||'')+'</td>'
              +'</tr>';
        }).join('');
        $('#overviewActivityBody').html(html);
    },'json').fail(function(){
        $('#overviewActivityBody').html('<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--t3);">Failed to load.</td></tr>');
    });
}

/* ─────────────────────── LOGIN LOGS ─────────────────────── */
var _allLoginRows = [];
var urlMap = { Activity:'superadmin/monitor/activity', SchoolLogins:'superadmin/monitor/school_logins', Logins:'superadmin/monitor/logins' };

function loadLoginLogs(){
    var type = $('#loginTypeSelect').val();
    var date = $('#loginDate').val();
    $('#loadLoginBtn').prop('disabled',true).find('i').addClass('fa-spin');
    $.ajax({ url: BASE_URL+urlMap[type], type:'POST', data:{ date:date },
        success:function(r){
            if(r.status!=='success'){ saToast(r.message||'Failed.','error'); return; }
            _allLoginRows = r.rows || [];
            renderLoginLogs();
            $('#loginLogsMeta').text(_allLoginRows.length + ' entries for '+date);
        },
        error:function(){ saToast('Failed to load logs.','error'); },
        complete:function(){ $('#loadLoginBtn').prop('disabled',false).find('i').removeClass('fa-spin'); }
    });
}

function renderLoginLogs(){
    var search = $('#loginSearch').val().toLowerCase();
    var rows   = _allLoginRows.filter(function(r){
        if(!search) return true;
        var hay = [(r.action||''),(r.event||''),(r.sa_name||''),(r.admin_id||''),(r.school_uid||''),(r.ip||'')].join(' ').toLowerCase();
        return hay.indexOf(search) >= 0;
    });
    if(!rows.length){
        $('#loginLogsTbody').html('<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--t3);">No matching entries.</td></tr>');
        return;
    }
    var html = rows.map(function(log){
        var action = log.action || log.event || log.message || '—';
        var user   = log.sa_name || log.admin_id || log.user || '—';
        var school = log.school_uid || log.school_name || '—';
        var status = log.status || '';
        var statusHtml = status === 'failed'
            ? '<span class="label label-danger">Failed</span>'
            : (status === 'success' ? '<span class="label label-success">OK</span>' : '—');
        return '<tr>'
          +'<td style="font-family:var(--font-m);font-size:10.5px;color:var(--t3);">'+ts(log.timestamp)+'</td>'
          +'<td style="color:var(--sa3);font-family:var(--font-m);font-size:12px;">'+esc(action)+'</td>'
          +'<td style="font-size:12px;color:var(--t1);">'+esc(user)+'</td>'
          +'<td style="font-size:11px;color:var(--t3);">'+esc(school)+'</td>'
          +'<td style="font-size:10.5px;color:var(--t4);">'+esc(log.ip||'')+'</td>'
          +'<td>'+statusHtml+'</td>'
          +'</tr>';
    }).join('');
    $('#loginLogsTbody').html(html);
}

$('#loadLoginBtn').on('click', loadLoginLogs);
$('#loginSearch').on('input', renderLoginLogs);

/* ─────────────────────── API LOGS ───────────────────────── */
var _allApiRows = [];

function loadApiLogs(){
    var date = $('#apiDate').val();
    $('#loadApiBtn').prop('disabled',true).find('i').addClass('fa-spin');
    $.ajax({ url: BASE_URL+'superadmin/monitor/fetch_api_logs', type:'POST', data:{ date:date },
        success:function(r){
            if(r.status!=='success'){ saToast(r.message||'Failed.','error'); return; }
            _allApiRows = r.rows || [];
            renderApiLogs();
            $('#apiLogsMeta').text(_allApiRows.length + ' API calls recorded for '+date);
        },
        error:function(){ saToast('Failed to load API logs.','error'); },
        complete:function(){ $('#loadApiBtn').prop('disabled',false).find('i').removeClass('fa-spin'); }
    });
}

function renderApiLogs(){
    var search = $('#apiSearch').val().toLowerCase();
    var rows   = _allApiRows.filter(function(r){
        if(!search) return true;
        return (r.endpoint||'').toLowerCase().indexOf(search) >= 0;
    });
    if(!rows.length){
        $('#apiLogsTbody').html('<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--t3);">No API calls recorded.</td></tr>');
        return;
    }
    var methodColors = { GET:'#22c55e', POST:'#3b82f6', PUT:'#f59e0b', PATCH:'#f59e0b', DELETE:'#ef4444' };
    var html = rows.map(function(log){
        var mc  = methodColors[log.method] || '#9ca3af';
        var dur = log.duration_ms ? fmtMs(parseInt(log.duration_ms)) : '—';
        var ok  = log.status !== 'error' && log.status !== 'timeout';
        return '<tr>'
          +'<td style="font-family:var(--font-m);font-size:10.5px;color:var(--t3);">'+ts(log.timestamp)+'</td>'
          +'<td><span style="font-size:10.5px;font-family:var(--font-m);font-weight:700;color:'+mc+';">'+esc(log.method||'POST')+'</span></td>'
          +'<td style="font-family:var(--font-m);font-size:11.5px;color:var(--t1);word-break:break-all;">'+esc(log.endpoint||'—')+'</td>'
          +'<td style="font-family:var(--font-m);font-size:11.5px;color:'+(parseInt(log.duration_ms)>1000?'#ef4444':'var(--t2)')+';">'+dur+'</td>'
          +'<td style="font-size:11.5px;">'+esc(log.sa_name||'—')+'</td>'
          +'<td style="font-size:10.5px;color:var(--t4);">'+esc(log.ip||'')+'</td>'
          +'<td><span class="label '+(ok?'label-success':'label-danger')+'">'+esc(log.status||'—')+'</span></td>'
          +'</tr>';
    }).join('');
    $('#apiLogsTbody').html(html);
}

$('#loadApiBtn').on('click', loadApiLogs);
$('#apiSearch').on('input', renderApiLogs);

/* ─────────────────────── ERROR LOGS ─────────────────────── */
var _errFilter = 'all';

function loadErrorLogs(){
    var date = $('#errorDate').val();
    $('#loadErrorBtn').prop('disabled',true).find('i').addClass('fa-spin');
    $.ajax({ url: BASE_URL+'superadmin/monitor/errors', type:'POST', data:{ date:date },
        success:function(r){
            if(r.status!=='success'){ saToast(r.message||'Failed.','error'); return; }
            _allErrRows = r.rows || [];
            updateErrorCounts();
            renderErrorLogs();
            $('#errorLogsMeta').text(_allErrRows.length + ' error entries for '+date);
        },
        error:function(){ saToast('Failed to load error logs.','error'); },
        complete:function(){ $('#loadErrorBtn').prop('disabled',false).find('i').removeClass('fa-spin'); }
    });
}

function updateErrorCounts(){
    var cnt = {error:0, warning:0, info:0};
    _allErrRows.forEach(function(r){ var l=(r.level||r.severity||'info').toLowerCase(); if(cnt[l]!==undefined) cnt[l]++; });
    $('#errCntError').text(cnt.error);
    $('#errCntWarning').text(cnt.warning);
    $('#errCntInfo').text(cnt.info);
    $('#errCntTotal').text(_allErrRows.length);
}

function renderErrorLogs(){
    var rows = _allErrRows.filter(function(r){
        if(_errFilter === 'all') return true;
        return (r.level||r.severity||'info').toLowerCase() === _errFilter;
    });
    if(!rows.length){
        $('#errorLogsTbody').html('<tr><td colspan="5" style="text-align:center;padding:24px;color:var(--t3);">No error entries found.</td></tr>');
        return;
    }
    var lvlCfg = {
        error:   { cls:'label-danger',   color:'rgba(239,68,68,.06)' },
        warning: { cls:'label-warning',  color:'rgba(245,158,11,.06)' },
        info:    { cls:'label-info',     color:'' },
    };
    var html = rows.map(function(log){
        var lvl = (log.level||log.severity||'info').toLowerCase();
        var cfg = lvlCfg[lvl] || lvlCfg.info;
        var msg = log.message || log.error || log.event || '—';
        var ctx = log.file || log.context || log.url || '';
        return '<tr style="background:'+cfg.color+';">'
          +'<td style="font-family:var(--font-m);font-size:10.5px;color:var(--t3);">'+ts(log.timestamp)+'</td>'
          +'<td><span class="label '+cfg.cls+'" style="text-transform:capitalize;">'+esc(lvl)+'</span></td>'
          +'<td style="font-size:12px;color:var(--t1);word-break:break-word;">'+esc(msg)+'</td>'
          +'<td style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);">'+esc(ctx)+'</td>'
          +'<td style="font-size:10.5px;color:var(--t4);">'+esc(log.ip||'')+'</td>'
          +'</tr>';
    }).join('');
    $('#errorLogsTbody').html(html);
}

$('#loadErrorBtn').on('click', loadErrorLogs);
$(document).on('click','.err-filter', function(){
    $('.err-filter').removeClass('active').css('opacity','.7');
    $(this).addClass('active').css('opacity','1');
    _errFilter = $(this).data('level');
    renderErrorLogs();
});

/* ─────────────────────── SERVER HEALTH ──────────────────── */
function checkHealth(){
    $('#healthKpiVal').text('...');
    $('#healthBtnIcon').addClass('fa-spin');
    fetchHealth();
}
window.checkHealth = checkHealth;

$('#refreshHealthBtn').on('click', fetchHealth);

function fetchHealth(){
    $('#refreshHealthBtn').prop('disabled',true);
    $('#healthRefreshIcon').addClass('fa-spin');

    $.ajax({ url: BASE_URL+'superadmin/monitor/system_health', type:'POST',
        success:function(r){
            if(r.status!=='success'){ saToast('Health check failed.','error'); return; }
            // mysql_ok is only checked when MySQL is actually configured in the SA panel
            var allOk = r.firebase_ok && (!r.mysql_configured || r.mysql_ok);

            // Update KPI cards
            $('#healthKpiVal').text(allOk ? 'Healthy' : 'Issues');
            $('#healthKpi').css('border-color', allOk ? 'rgba(34,197,94,.3)' : 'rgba(239,68,68,.3)');
            $('#fbKpiStatus').text(r.firebase_ok ? 'OK' : 'Error').css('color', r.firebase_ok ? '#22c55e' : '#ef4444');
            $('#fbKpiMs').html('<i class="fa fa-database" style="color:#f59e0b;margin-right:3px;"></i>'+r.firebase_ms+'ms response');
            $('#overviewFbStatus').text(r.firebase_ok ? '✓ Connected ('+r.firebase_ms+'ms)' : '✗ Error').css('color', r.firebase_ok ? '#22c55e' : '#ef4444');
            $('#overviewDisk').text(r.disk_free_mb + ' MB free');
            $('#healthBtnIcon').removeClass('fa-spin');

            renderHealthPanel(r);
        },
        error:function(){ saToast('Health check failed.','error'); $('#healthBtnIcon').removeClass('fa-spin'); },
        complete:function(){ $('#refreshHealthBtn').prop('disabled',false); $('#healthRefreshIcon').removeClass('fa-spin'); }
    });
}

function gauge(pct, color){
    return '<div class="gauge-bar"><div class="gauge-fill" style="width:'+pct+'%;background:'+color+';"></div></div>';
}

function svc(label, ok, detail){
    var c = ok ? '#22c55e' : '#ef4444';
    var bg = ok ? 'rgba(34,197,94,.1)' : 'rgba(239,68,68,.1)';
    var icon = ok ? 'fa-check-circle' : 'fa-times-circle';
    return '<div class="svc-badge" style="background:'+bg+';border:1px solid '+c+'44;color:'+c+';">'
         + '<i class="fa '+icon+'"></i> '+esc(label)
         + (detail ? '<span style="font-size:10px;color:var(--t3);font-weight:400;margin-left:4px;">'+esc(detail)+'</span>' : '')
         + '</div>';
}

function renderHealthPanel(r){
    var memPct  = r.memory_used_pct || 0;
    var diskPct = r.disk_used_pct  || 0;
    var memColor  = memPct  > 80 ? '#ef4444' : memPct  > 60 ? '#f59e0b' : '#22c55e';
    var diskColor = diskPct > 85 ? '#ef4444' : diskPct > 70 ? '#f59e0b' : '#22c55e';

    var html = '<div class="row">';

    // Services column
    html += '<div class="col-md-4" style="margin-bottom:16px;">'
          + '<div style="font-size:11px;color:var(--t3);font-family:var(--font-m);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Services</div>'
          + '<div style="display:flex;flex-direction:column;gap:7px;">'
          + svc('Firebase DB', r.firebase_ok, r.firebase_ok ? r.firebase_ms+'ms' : (r.firebase_error||'').substring(0,40))
          + svc('MySQL / DB',  r.mysql_ok || !r.mysql_configured,
                    r.mysql_configured ? (r.mysql_ok ? 'Connected' : (r.mysql_error||'').substring(0,40)) : 'N/A (Firebase-only panel)')
          + svc('OPcache',     r.opcache_enabled, r.opcache_enabled ? 'Enabled' : 'Disabled')
          + '</div></div>';

    // Resource gauges column
    html += '<div class="col-md-4" style="margin-bottom:16px;">'
          + '<div style="font-size:11px;color:var(--t3);font-family:var(--font-m);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Resources</div>'
          + '<div style="font-size:12px;color:var(--t2);margin-bottom:6px;">Memory <span style="float:right;font-family:var(--font-m);font-size:11px;">'+r.memory_used_mb+' / '+r.memory_limit_mb+' MB ('+memPct+'%)</span></div>'
          + gauge(memPct, memColor)
          + '<div style="font-size:12px;color:var(--t2);margin-top:12px;margin-bottom:6px;">Disk <span style="float:right;font-family:var(--font-m);font-size:11px;">'+r.disk_used_mb+' / '+r.disk_total_mb+' MB ('+diskPct+'%)</span></div>'
          + gauge(diskPct, diskColor);

    if(r.load_avg){
        html += '<div style="font-size:12px;color:var(--t2);margin-top:12px;margin-bottom:4px;">CPU Load Average</div>'
              + '<div style="font-family:var(--font-m);font-size:12px;color:var(--sa3);">1m: '+r.load_avg['1m']+'  &nbsp; 5m: '+r.load_avg['5m']+'  &nbsp; 15m: '+r.load_avg['15m']+'</div>';
    }
    html += '</div>';

    // Environment column
    html += '<div class="col-md-4" style="margin-bottom:16px;">'
          + '<div style="font-size:11px;color:var(--t3);font-family:var(--font-m);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px;">Environment</div>'
          + '<table style="width:100%;font-size:12px;">';
    var env = [
        ['PHP',        r.php_version],
        ['CodeIgniter',r.ci_version],
        ['Server',    (r.server_software||'—').split('/').slice(0,2).join('/')],
        ['Server Time', r.server_time],
        ['Peak Mem',   r.memory_peak_mb+' MB'],
    ];
    env.forEach(function(e){
        html += '<tr><td style="color:var(--t3);padding:4px 0;width:90px;">'+esc(e[0])+'</td>'
              + '<td style="font-family:var(--font-m);font-size:11.5px;color:var(--t1);">'+esc(String(e[1]||'—'))+'</td></tr>';
    });
    html += '</table></div>';

    html += '</div>'; // /.row

    // Firebase response time bar
    if(r.firebase_ok){
        var fbMs  = r.firebase_ms;
        var fbPct = Math.min(100, Math.round(fbMs / 3000 * 100));
        var fbCol = fbMs < 300 ? '#22c55e' : fbMs < 1000 ? '#f59e0b' : '#ef4444';
        html += '<div style="border-top:1px solid var(--border);padding-top:14px;margin-top:4px;">'
              + '<div style="font-size:12px;color:var(--t2);margin-bottom:6px;">Firebase Response Time <span style="float:right;font-family:var(--font-m);font-size:11px;color:'+fbCol+';">'+fbMs+'ms</span></div>'
              + gauge(fbPct, fbCol)
              + '<div style="font-size:10.5px;color:var(--t3);margin-top:4px;font-family:var(--font-m);">Good &lt;300ms · Slow &lt;1000ms · Critical &gt;1000ms</div>'
              + '</div>';
    }

    $('#healthContent').html(html);
}

/* ─────────────────────── CLEAR LOGS ─────────────────────── */
var _clearType, _clearDate;
function showClearModal(type, date){
    _clearType = type; _clearDate = date;
    $('#clearLogsTypeLabel').text(type);
    $('#clearLogsDateLabel').text(date);
    $('#clearLogsModal').modal('show');
}
$('#clearLoginLogsBtn').on('click', function(){ showClearModal($('#loginTypeSelect').val(), $('#loginDate').val()); });
$('#clearErrorLogsBtn').on('click', function(){ showClearModal('Errors', $('#errorDate').val()); });
$('#clearApiLogsBtn').on('click',   function(){ showClearModal('ApiUsage', $('#apiDate').val()); });

$('#confirmClearLogsBtn').on('click', function(){
    var $btn = $(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i>');
    $.ajax({ url: BASE_URL+'superadmin/monitor/clear_logs', type:'POST', data:{ log_type:_clearType, date:_clearDate },
        success:function(r){
            saToast(r.message||r.status, r.status);
            if(r.status==='success') $('#clearLogsModal').modal('hide');
        },
        error:function(){ saToast('Server error.','error'); },
        complete:function(){ $btn.prop('disabled',false).html('<i class="fa fa-trash"></i> Delete'); }
    });
});

/* ─────────────────────── AUTO-CLEANUP OLD LOGS ──────────── */
$('#cleanupOldLogsBtn').on('click', function(){
    var days = parseInt($('#cleanupRetainDays').val()) || 30;
    if(!confirm('This will permanently delete all log entries older than '+days+' day(s). Continue?')) return;
    var $btn = $(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Running...');
    $('#cleanupResult').text('');
    $.ajax({ url: BASE_URL+'superadmin/monitor/cleanup_old_logs', type:'POST', data:{retain_days:days},
        success:function(r){
            if(r.status==='success'){
                $('#cleanupResult').html('<span style="color:var(--green);"><i class="fa fa-check"></i> '+esc(r.message)+'</span>');
                saToast(r.message,'success');
            } else {
                $('#cleanupResult').html('<span style="color:var(--rose);">'+(r.message||'Cleanup failed.')+'</span>');
                saToast(r.message||'Cleanup failed.','error');
            }
        },
        error:function(){ saToast('Server error.','error'); },
        complete:function(){ $btn.prop('disabled',false).html('<i class="fa fa-trash-o"></i> Run Cleanup'); }
    });
});

/* ─────────────────────── AUTO-REFRESH ───────────────────── */
$('#autoRefreshToggle').on('change', function(){
    if(this.checked){
        _autoTimer = setInterval(fetchHealth, 30000);
        saToast('Auto-refresh enabled (30s).','info');
    } else {
        clearInterval(_autoTimer);
        saToast('Auto-refresh disabled.','info');
    }
});

/* ─────────────────────── THEME CHANGE → CHART REDRAW ───── */
new MutationObserver(function(){
    if(usageChart){ usageChart.options.scales.x.ticks.color=getTextColor(); usageChart.options.scales.x.grid.color=getGridColor(); usageChart.options.scales.y.ticks.color=getTextColor(); usageChart.options.scales.y.grid.color=getGridColor(); usageChart.update('none'); }
    if(apiChart){   apiChart.options.scales.x.ticks.color=getTextColor();   apiChart.options.scales.x.grid.color=getGridColor();   apiChart.options.scales.y.ticks.color=getTextColor();   apiChart.options.scales.y.grid.color=getGridColor();   apiChart.update('none'); }
}).observe(document.body, { attributes:true, attributeFilter:['class','data-theme'] });

/* ─────────────────────── INIT ───────────────────────────── */
$(function(){
    loadFirebaseUsage();
    loadOverviewActivity();
    fetchHealth();             // auto-run on page load
});

})();
</script>
