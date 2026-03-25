<?php
$schools  = $schools  ?? [];
$schedule = $schedule ?? [];

$sch_enabled   = !empty($schedule['enabled']);
$sch_frequency = $schedule['frequency']   ?? 'daily';
$sch_day       = (int)($schedule['day_of_week'] ?? 0);
$sch_time      = $schedule['backup_time'] ?? '02:00';
$sch_scope     = $schedule['scope']       ?? 'all';
$sch_retention = (int)($schedule['retention'] ?? 7);
$sch_type      = $schedule['backup_type'] ?? 'firebase';
$sch_last_run  = $schedule['last_run']    ?? '';
$sch_cron_key  = $schedule['cron_key']    ?? '—';
?>

<section class="content-header">
    <h1><i class="fa fa-database" style="color:var(--sa3);margin-right:10px;font-size:20px;"></i>Backup Management</h1>
    <ol class="breadcrumb">
        <li><a href="<?= base_url('superadmin/dashboard') ?>">Dashboard</a></li>
        <li class="active">Backups</li>
    </ol>
</section>

<section class="content" style="padding:20px 24px;">

    <!-- ── KPI Row ── -->
    <div class="row" style="margin-bottom:20px;" id="kpiRow">
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:14px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:16px 14px;">
                <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;">Total Backups</div>
                <div id="kpiTotal" style="font-size:24px;font-weight:800;color:var(--t1);font-family:var(--font-d);">—</div>
                <div style="margin-top:5px;font-size:10px;color:var(--t4);"><i class="fa fa-database" style="color:#8b5cf6;margin-right:3px;"></i>All schools</div>
            </div>
        </div>
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:14px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:16px 14px;">
                <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;">Schools Covered</div>
                <div id="kpiSchools" style="font-size:24px;font-weight:800;color:var(--t1);font-family:var(--font-d);">—</div>
                <div style="margin-top:5px;font-size:10px;color:var(--t4);"><i class="fa fa-building" style="color:#22c55e;margin-right:3px;"></i>Have backups</div>
            </div>
        </div>
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:14px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:16px 14px;">
                <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;">Total Size</div>
                <div id="kpiSize" style="font-size:24px;font-weight:800;color:var(--t1);font-family:var(--font-d);">—</div>
                <div style="margin-top:5px;font-size:10px;color:var(--t4);"><i class="fa fa-hdd-o" style="color:#3b82f6;margin-right:3px;"></i>On disk</div>
            </div>
        </div>
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:14px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:16px 14px;">
                <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;">Manual</div>
                <div id="kpiManual" style="font-size:24px;font-weight:800;color:var(--t1);font-family:var(--font-d);">—</div>
                <div style="margin-top:5px;font-size:10px;color:var(--t4);"><i class="fa fa-hand-o-up" style="color:#f59e0b;margin-right:3px;"></i>Backups</div>
            </div>
        </div>
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:14px;">
            <div style="background:var(--bg2);border:1px solid var(--border);border-radius:12px;padding:16px 14px;">
                <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;">Scheduled</div>
                <div id="kpiAuto" style="font-size:24px;font-weight:800;color:var(--t1);font-family:var(--font-d);">—</div>
                <div style="margin-top:5px;font-size:10px;color:var(--t4);"><i class="fa fa-clock-o" style="color:#14b8a6;margin-right:3px;"></i>Auto backups</div>
            </div>
        </div>
        <div class="col-xs-6 col-sm-4 col-lg-2" style="margin-bottom:14px;">
            <div style="background:var(--bg2);border:1px solid <?= $sch_enabled ? 'rgba(34,197,94,.35)' : 'var(--border)' ?>;border-radius:12px;padding:16px 14px;">
                <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;">Auto-Schedule</div>
                <div style="font-size:24px;font-weight:800;color:<?= $sch_enabled ? '#22c55e' : 'var(--t3)' ?>;font-family:var(--font-d);"><?= $sch_enabled ? 'ON' : 'OFF' ?></div>
                <div style="margin-top:5px;font-size:10px;color:var(--t4);">
                    <i class="fa fa-calendar" style="color:<?= $sch_enabled ? '#22c55e' : 'var(--t4)' ?>;margin-right:3px;"></i>
                    <?= $sch_enabled ? ucfirst($sch_frequency) . ' @ ' . $sch_time : 'Not configured' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Tabs ── -->
    <ul class="nav nav-tabs" id="backupTabs" style="border-color:var(--border);margin-bottom:0;">
        <li class="active"><a href="#tab-overview"   data-toggle="tab"><i class="fa fa-dashboard"   style="margin-right:5px;"></i>Overview</a></li>
        <li><a href="#tab-manual"    data-toggle="tab"><i class="fa fa-plus-circle"   style="margin-right:5px;"></i>Manual Backup</a></li>
        <li><a href="#tab-schedule"  data-toggle="tab"><i class="fa fa-clock-o"       style="margin-right:5px;"></i>Schedule</a></li>
        <li><a href="#tab-restore"   data-toggle="tab"><i class="fa fa-history"       style="margin-right:5px;"></i>Restore</a></li>
    </ul>

    <style>
    .nav-tabs>li>a { color:var(--t3)!important; border-color:transparent!important; background:var(--bg2)!important; font-size:12.5px!important; }
    .nav-tabs>li.active>a,.nav-tabs>li>a:hover { color:var(--t1)!important; border-color:var(--border) var(--border) var(--bg2)!important; }
    .nav-tabs>li.active>a { color:var(--sa3)!important; border-top:2px solid var(--sa3)!important; }
    .tab-content { background:var(--bg2); border:1px solid var(--border); border-top:none; border-radius:0 0 var(--r) var(--r); padding:20px; }
    .bk-section-head { font-size:11px;font-weight:700;color:var(--t3);text-transform:uppercase;letter-spacing:.8px;font-family:var(--font-m);margin-bottom:12px;padding-bottom:8px;border-bottom:1px solid var(--border); }
    .restore-warn { background:rgba(239,68,68,.07);border:1px solid rgba(239,68,68,.22);border-radius:10px;padding:14px 16px;font-size:12.5px;color:var(--t2);line-height:1.7; }
    .type-badge-firebase { background:rgba(139,92,246,.12);color:#a78bfa;border:1px solid rgba(139,92,246,.25); }
    .type-badge-full     { background:rgba(20,184,166,.12);color:#2dd4bf;border:1px solid rgba(20,184,166,.25); }
    .type-badge-manual   { background:var(--blue-dim);color:var(--blue);border:1px solid var(--blue-ring); }
    .type-badge-scheduled{ background:var(--green-dim);color:var(--green);border:1px solid var(--green-ring); }
    .type-badge-safety   { background:var(--amber-dim);color:var(--amber);border:1px solid var(--amber-ring); }
    </style>

    <div class="tab-content">

        <!-- ══════════════════════════════════════════════════════ OVERVIEW -->
        <div class="tab-pane active" id="tab-overview">

            <!-- Schedule status banner -->
            <div style="background:<?= $sch_enabled ? 'rgba(34,197,94,.07)' : 'var(--bg3)' ?>;border:1px solid <?= $sch_enabled ? 'rgba(34,197,94,.22)' : 'var(--border)' ?>;border-radius:10px;padding:14px 18px;margin-bottom:20px;display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
                <div style="display:flex;align-items:center;gap:12px;">
                    <i class="fa fa-clock-o" style="font-size:20px;color:<?= $sch_enabled ? '#22c55e' : 'var(--t4)' ?>;"></i>
                    <div>
                        <div style="font-size:13px;font-weight:600;color:var(--t1);">
                            Auto-Backup is <strong style="color:<?= $sch_enabled ? '#22c55e' : '#ef4444' ?>;"><?= $sch_enabled ? 'ENABLED' : 'DISABLED' ?></strong>
                            <?php if($sch_enabled): ?>
                            — <?= ucfirst($sch_frequency) ?> at <code style="font-size:12px;"><?= htmlspecialchars($sch_time) ?></code>
                            (<?= ucfirst($sch_type) ?> backup, retain <?= $sch_retention ?> per school)
                            <?php endif; ?>
                        </div>
                        <?php if($sch_last_run): ?>
                        <div style="font-size:11.5px;color:var(--t3);font-family:var(--font-m);">Last run: <?= htmlspecialchars($sch_last_run) ?> — <?= htmlspecialchars($schedule['last_run_count'] ?? 0) ?> school(s) backed up</div>
                        <?php else: ?>
                        <div style="font-size:11.5px;color:var(--t3);">No scheduled runs recorded yet.</div>
                        <?php endif; ?>
                    </div>
                </div>
                <div style="display:flex;gap:8px;flex-wrap:wrap;">
                    <a href="#tab-schedule" class="btn btn-default btn-sm" data-toggle="tab" onclick="$('#backupTabs a[href=\'#tab-schedule\']').tab('show')">
                        <i class="fa fa-cog"></i> Configure Schedule
                    </a>
                    <button class="btn btn-primary btn-sm" id="runNowBtnOverview">
                        <i class="fa fa-play"></i> Run All Schools Now
                    </button>
                </div>
            </div>

            <!-- Info banner -->
            <div style="background:var(--sa-dim);border:1px solid var(--sa-ring);border-radius:10px;padding:12px 16px;margin-bottom:20px;font-size:12.5px;color:var(--t2);line-height:1.7;display:flex;align-items:flex-start;gap:10px;">
                <i class="fa fa-info-circle" style="color:var(--sa3);flex-shrink:0;margin-top:2px;"></i>
                <div>
                    <strong style="color:var(--sa3);">Backup Strategy:</strong>
                    <em>Firebase</em> backups export the school's entire Firebase subtree as a JSON file (fast, ~seconds).
                    <em>Full</em> backups additionally include system configuration and uploaded file manifest (ZIP if server supports it).
                    All backup files are stored in <code style="font-size:11px;background:var(--bg3);padding:1px 5px;border-radius:4px;">application/backups/</code> (protected by .htaccess, not web-accessible).
                    A <strong>safety backup</strong> is always created automatically before any restore.
                </div>
            </div>

            <!-- Recent backups (all schools) -->
            <div class="bk-section-head"><i class="fa fa-history" style="margin-right:5px;color:var(--sa3);"></i>Recent Backups — All Schools</div>
            <div style="overflow-x:auto;">
                <table class="table" style="margin:0;font-size:12.5px;min-width:700px;">
                    <thead><tr>
                        <th>Backup ID</th>
                        <th>School</th>
                        <th>Created At</th>
                        <th>By</th>
                        <th>Type</th>
                        <th>Format</th>
                        <th>Size</th>
                        <th>Action</th>
                    </tr></thead>
                    <tbody id="overviewBackupsTbody">
                        <tr><td colspan="8" style="text-align:center;padding:28px;color:var(--t3);"><i class="fa fa-spinner fa-spin"></i> Loading...</td></tr>
                    </tbody>
                </table>
            </div>

        </div><!-- /tab-overview -->

        <!-- ══════════════════════════════════════════════════ MANUAL BACKUP -->
        <div class="tab-pane" id="tab-manual">

            <div class="row">

                <!-- Left: controls + history -->
                <div class="col-md-8">

                    <!-- Create backup form -->
                    <div class="bk-section-head"><i class="fa fa-plus-circle" style="margin-right:5px;color:var(--sa3);"></i>Create New Backup</div>
                    <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px;">
                        <div class="form-group" style="margin:0;flex:1;min-width:180px;">
                            <label>School</label>
                            <select class="form-control" id="manualSchoolSelect">
                                <option value="">— Select a School —</option>
                                <?php foreach($schools as $uid => $name): ?>
                                <option value="<?= htmlspecialchars($uid) ?>"><?= htmlspecialchars($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label>Backup Type</label>
                            <select class="form-control" id="manualBackupType" style="width:150px;">
                                <option value="firebase">Firebase Only (JSON)</option>
                                <option value="full">Full Backup (ZIP)</option>
                            </select>
                        </div>
                        <div>
                            <label style="visibility:hidden;display:block;">.</label>
                            <button class="btn btn-primary" id="createBackupBtn" disabled>
                                <i class="fa fa-database"></i> Create Backup
                            </button>
                        </div>
                    </div>

                    <!-- Full backup details (toggled) -->
                    <div id="fullBackupInfo" style="display:none;background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:12.5px;color:var(--t2);">
                        <strong style="color:var(--sa3);">Full Backup includes:</strong>
                        <ul style="margin:6px 0 0;padding-left:18px;line-height:1.9;">
                            <li>Complete Firebase school subtree (<code>Schools/{name}/</code>)</li>
                            <li>System configuration (PHP version, DB driver, server info) — <em>passwords excluded</em></li>
                            <li>Uploaded files manifest (file paths + sizes) for this school</li>
                            <li>Actual uploaded files if ≤ 50 MB (requires ZipArchive PHP extension)</li>
                        </ul>
                    </div>

                    <!-- School backup history -->
                    <div class="bk-section-head" style="margin-top:8px;"><i class="fa fa-list" style="margin-right:5px;color:var(--sa3);"></i>Backup History for Selected School</div>
                    <div style="font-size:11.5px;color:var(--t3);font-family:var(--font-m);margin-bottom:8px;" id="manualBackupMeta">Select a school above to load its backups.</div>

                    <div style="overflow-x:auto;">
                        <table class="table" style="margin:0;font-size:12.5px;min-width:620px;">
                            <thead><tr>
                                <th>Backup ID</th>
                                <th>Created At</th>
                                <th>By</th>
                                <th>Type</th>
                                <th>Size</th>
                                <th>Last Restored</th>
                                <th style="width:130px;">Actions</th>
                            </tr></thead>
                            <tbody id="manualBackupsTbody">
                                <tr><td colspan="7" style="text-align:center;padding:28px;color:var(--t3);">Select a school to view its backups.</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Right: stats panel -->
                <div class="col-md-4">
                    <div class="bk-section-head"><i class="fa fa-bar-chart" style="margin-right:5px;color:var(--sa3);"></i>School Backup Stats</div>
                    <div style="background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:16px;">
                        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-bottom:14px;">
                            <div style="text-align:center;padding:14px 10px;background:var(--bg2);border-radius:8px;border:1px solid var(--border);">
                                <div id="schoolStatCount" style="font-size:22px;font-weight:800;color:var(--sa3);font-family:var(--font-d);">—</div>
                                <div style="font-size:10.5px;color:var(--t3);">Backups</div>
                            </div>
                            <div style="text-align:center;padding:14px 10px;background:var(--bg2);border-radius:8px;border:1px solid var(--border);">
                                <div id="schoolStatSize" style="font-size:22px;font-weight:800;color:var(--t1);font-family:var(--font-d);">—</div>
                                <div style="font-size:10.5px;color:var(--t3);">Total Size</div>
                            </div>
                        </div>
                        <div id="schoolStatLatest" style="font-size:11.5px;color:var(--t3);text-align:center;">—</div>
                    </div>

                    <div class="restore-warn">
                        <i class="fa fa-exclamation-triangle" style="color:#ef4444;margin-right:6px;"></i>
                        <strong style="color:#ef4444;">Restore Warning</strong><br>
                        Restoring overwrites <strong>all live Firebase data</strong> for the school.
                        A safety backup is always created first.<br>
                        You must type <strong style="font-family:var(--font-m);color:var(--sa3);">RESTORE</strong> to confirm.
                    </div>
                </div>

            </div>

        </div><!-- /tab-manual -->

        <!-- ══════════════════════════════════════════════════ SCHEDULE -->
        <div class="tab-pane" id="tab-schedule">

            <div class="row">

                <!-- Schedule config form -->
                <div class="col-md-7">
                    <div class="bk-section-head"><i class="fa fa-calendar" style="margin-right:5px;color:var(--sa3);"></i>Backup Schedule Configuration</div>

                    <form id="scheduleForm">

                        <!-- Enable toggle -->
                        <div style="display:flex;align-items:center;justify-content:space-between;background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:14px 16px;margin-bottom:16px;">
                            <div>
                                <div style="font-size:13px;font-weight:600;color:var(--t1);">Enable Automatic Backups</div>
                                <div style="font-size:11.5px;color:var(--t3);">When enabled, the cron job or manual "Run Now" button will back up all schools.</div>
                            </div>
                            <label style="position:relative;display:inline-flex;align-items:center;cursor:pointer;margin:0;text-transform:none !important;letter-spacing:0 !important;">
                                <input type="checkbox" id="schedEnabled" name="enabled" value="1" <?= $sch_enabled ? 'checked' : '' ?> style="width:0;height:0;opacity:0;position:absolute;">
                                <span id="schedToggleTrack" style="width:44px;height:24px;border-radius:12px;background:<?= $sch_enabled ? 'var(--sa)' : 'var(--border)' ?>;transition:background .2s;display:block;position:relative;">
                                    <span id="schedToggleThumb" style="width:18px;height:18px;border-radius:50%;background:#fff;position:absolute;top:3px;left:<?= $sch_enabled ? '23px' : '3px' ?>;transition:left .2s;"></span>
                                </span>
                            </label>
                        </div>

                        <div class="row">
                            <div class="col-sm-4">
                                <div class="form-group">
                                    <label>Frequency</label>
                                    <select class="form-control" name="frequency" id="schedFrequency">
                                        <option value="daily"  <?= $sch_frequency === 'daily'  ? 'selected' : '' ?>>Daily</option>
                                        <option value="weekly" <?= $sch_frequency === 'weekly' ? 'selected' : '' ?>>Weekly</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-sm-4" id="dayOfWeekGroup" style="<?= $sch_frequency === 'daily' ? 'display:none;' : '' ?>">
                                <div class="form-group">
                                    <label>Day of Week</label>
                                    <select class="form-control" name="day_of_week" id="schedDay">
                                        <?php $days = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
                                        foreach($days as $i => $d): ?>
                                        <option value="<?= $i ?>" <?= $sch_day === $i ? 'selected' : '' ?>><?= $d ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group">
                                    <label>Time (24h)</label>
                                    <input type="time" class="form-control" name="backup_time" id="schedTime" value="<?= htmlspecialchars($sch_time) ?>">
                                </div>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-sm-4">
                                <div class="form-group">
                                    <label>Backup Type</label>
                                    <select class="form-control" name="backup_type" id="schedType">
                                        <option value="firebase" <?= $sch_type === 'firebase' ? 'selected' : '' ?>>Firebase Only (JSON)</option>
                                        <option value="full"     <?= $sch_type === 'full'     ? 'selected' : '' ?>>Full Backup (ZIP)</option>
                                    </select>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group">
                                    <label>Retention (per school)</label>
                                    <input type="number" class="form-control" name="retention" id="schedRetention" value="<?= $sch_retention ?>" min="1" max="30">
                                    <span style="font-size:10.5px;color:var(--t3);">Keep last N backups, auto-delete older</span>
                                </div>
                            </div>
                            <div class="col-sm-4">
                                <div class="form-group">
                                    <label>Scope</label>
                                    <select class="form-control" name="scope" id="schedScope">
                                        <option value="all" <?= $sch_scope === 'all' ? 'selected' : '' ?>>All Schools</option>
                                    </select>
                                    <span style="font-size:10.5px;color:var(--t3);">All registered schools will be backed up</span>
                                </div>
                            </div>
                        </div>

                        <div style="display:flex;gap:8px;flex-wrap:wrap;">
                            <button type="submit" class="btn btn-primary"><i class="fa fa-save"></i> Save Schedule</button>
                            <button type="button" class="btn btn-success" id="runNowBtnSchedule">
                                <i class="fa fa-play"></i> Run All Schools Now
                            </button>
                        </div>

                    </form>
                </div>

                <!-- Cron setup + last run info -->
                <div class="col-md-5">
                    <div class="bk-section-head"><i class="fa fa-terminal" style="margin-right:5px;color:var(--sa3);"></i>Cron / Task Scheduler Setup</div>

                    <div style="background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:16px;font-size:12.5px;color:var(--t2);">
                        <p style="margin-bottom:10px;">Set up your server's cron job or Windows Task Scheduler to call this URL at the configured time:</p>

                        <!-- Linux crontab -->
                        <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Linux crontab</div>
                        <div style="background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:10px 12px;font-family:var(--font-m);font-size:11.5px;color:var(--sa3);word-break:break-all;margin-bottom:12px;" id="cronCommand">
                            <?php
                            $time_parts = explode(':', $sch_time);
                            $ch = (int)($time_parts[0] ?? 2);
                            $cm = (int)($time_parts[1] ?? 0);
                            $cron_url = base_url("backup_cron/{$sch_cron_key}");
                            ?>
                            <?= $sch_frequency === 'weekly'
                                ? "{$cm} {$ch} * * {$sch_day} curl -s \"{$cron_url}\" >> /var/log/grader_backup.log 2>&1"
                                : "{$cm} {$ch} * * * curl -s \"{$cron_url}\" >> /var/log/grader_backup.log 2>&1" ?>
                        </div>

                        <!-- Windows Task Scheduler -->
                        <div style="font-size:10.5px;color:var(--t3);font-family:var(--font-m);text-transform:uppercase;letter-spacing:.5px;margin-bottom:5px;">Windows (PowerShell)</div>
                        <div style="background:var(--bg);border:1px solid var(--border);border-radius:6px;padding:10px 12px;font-family:var(--font-m);font-size:11.5px;color:var(--sa3);word-break:break-all;margin-bottom:12px;">
                            Invoke-WebRequest -Uri "<?= $cron_url ?>" -UseBasicParsing
                        </div>

                        <div style="font-size:11px;color:var(--t3);">
                            <i class="fa fa-key" style="margin-right:4px;"></i>
                            Cron key: <code style="font-family:var(--font-m);font-size:11px;"><?= htmlspecialchars($sch_cron_key) ?></code>
                            — Stored in Firebase <code>System/BackupSchedule/cron_key</code>
                        </div>
                    </div>

                    <!-- Last run status -->
                    <div class="bk-section-head"><i class="fa fa-info-circle" style="margin-right:5px;color:var(--sa3);"></i>Last Scheduled Run</div>
                    <div style="background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:14px;font-size:12.5px;color:var(--t2);" id="lastRunPanel">
                        <?php if($sch_last_run): ?>
                        <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                            <span style="color:var(--t3);">Last Run</span>
                            <strong><?= htmlspecialchars($sch_last_run) ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;margin-bottom:6px;">
                            <span style="color:var(--t3);">Schools Backed Up</span>
                            <strong><?= htmlspecialchars($schedule['last_run_count'] ?? '—') ?></strong>
                        </div>
                        <div style="display:flex;justify-content:space-between;">
                            <span style="color:var(--t3);">Triggered By</span>
                            <strong><?= htmlspecialchars($schedule['last_run_by'] ?? '—') ?></strong>
                        </div>
                        <?php else: ?>
                        <span style="color:var(--t4);">No scheduled runs have been executed yet.</span>
                        <?php endif; ?>
                    </div>

                </div>

            </div>

            <!-- Run result panel (shown after run) -->
            <div id="runResultPanel" style="display:none;margin-top:20px;">
                <div class="bk-section-head"><i class="fa fa-play" style="margin-right:5px;color:var(--sa3);"></i>Last Run Results</div>
                <div id="runResultBody"></div>
            </div>

        </div><!-- /tab-schedule -->

        <!-- ══════════════════════════════════════════════════ RESTORE -->
        <div class="tab-pane" id="tab-restore">

            <ul class="nav nav-pills" id="restoreSubTabs" style="margin-bottom:20px;">
                <li class="active"><a href="#restore-existing" data-toggle="pill"><i class="fa fa-database" style="margin-right:5px;"></i>From Existing Backup</a></li>
                <li><a href="#restore-upload" data-toggle="pill"><i class="fa fa-upload" style="margin-right:5px;"></i>Upload &amp; Restore</a></li>
            </ul>

            <style>
            .nav-pills>li>a { background:var(--bg3)!important;border:1px solid var(--border)!important;color:var(--t2)!important;border-radius:var(--r-sm)!important;font-size:12.5px!important; }
            .nav-pills>li.active>a { background:var(--sa-dim)!important;border-color:var(--sa-ring)!important;color:var(--sa3)!important; }
            </style>

            <div class="tab-content" style="background:transparent;border:none;padding:0;">

                <!-- From existing -->
                <div class="tab-pane active" id="restore-existing">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="bk-section-head"><i class="fa fa-list" style="margin-right:5px;color:var(--sa3);"></i>Select School &amp; Backup</div>

                            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;">
                                <select class="form-control" id="restoreSchoolSelect" style="max-width:250px;">
                                    <option value="">— Select School —</option>
                                    <?php foreach($schools as $uid => $name): ?>
                                    <option value="<?= htmlspecialchars($uid) ?>"><?= htmlspecialchars($name) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button class="btn btn-default btn-sm" id="loadRestoreBackupsBtn" disabled>
                                    <i class="fa fa-refresh" id="loadRestoreIcon"></i> Load Backups
                                </button>
                            </div>

                            <div style="overflow-x:auto;">
                                <table class="table" style="margin:0;font-size:12.5px;min-width:560px;">
                                    <thead><tr>
                                        <th>Backup ID</th>
                                        <th>Created At</th>
                                        <th>Type</th>
                                        <th>Size</th>
                                        <th>Last Restored</th>
                                        <th style="width:80px;">Action</th>
                                    </tr></thead>
                                    <tbody id="restoreBackupsTbody">
                                        <tr><td colspan="6" style="text-align:center;padding:28px;color:var(--t3);">Select a school to view backups.</td></tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="restore-warn" style="margin-top:30px;">
                                <i class="fa fa-exclamation-triangle" style="color:#ef4444;margin-right:6px;"></i>
                                <strong style="color:#ef4444;">Destructive Operation</strong><br>
                                Restoring will <strong>overwrite all live Firebase data</strong> for the school. Current data is permanently replaced by the backup snapshot.<br><br>
                                A <strong>safety backup</strong> is auto-created before every restore. You must type <strong style="font-family:var(--font-m);color:var(--sa3);">RESTORE</strong> to confirm.
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Upload & restore -->
                <div class="tab-pane" id="restore-upload">
                    <div class="row">
                        <div class="col-md-7">
                            <div class="bk-section-head"><i class="fa fa-upload" style="margin-right:5px;color:var(--sa3);"></i>Upload Backup File &amp; Restore</div>

                            <form id="uploadRestoreForm" enctype="multipart/form-data">
                                <div class="form-group">
                                    <label>Backup File (.json)</label>
                                    <div style="border:2px dashed var(--border);border-radius:10px;padding:24px;text-align:center;cursor:pointer;" id="uploadDropZone">
                                        <i class="fa fa-cloud-upload" style="font-size:28px;color:var(--sa3);opacity:.5;display:block;margin-bottom:8px;"></i>
                                        <div style="font-size:13px;color:var(--t3);">Drag &amp; drop a <code>.json</code> backup file, or <label for="backupFileInput" style="color:var(--sa3);cursor:pointer;text-decoration:underline;text-transform:none !important;letter-spacing:0 !important;font-weight:500 !important;">browse</label></div>
                                        <input type="file" id="backupFileInput" name="backup_file" accept=".json" style="display:none;">
                                    </div>
                                    <div id="uploadFileInfo" style="margin-top:8px;font-size:12px;color:var(--t3);font-family:var(--font-m);"></div>
                                </div>

                                <!-- File preview -->
                                <div id="uploadPreviewBox" style="display:none;background:var(--bg3);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:14px;font-size:12.5px;">
                                    <div style="font-size:11px;color:var(--t3);font-family:var(--font-m);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px;">Backup File Preview</div>
                                    <div id="uploadPreviewContent"></div>
                                </div>

                                <div class="form-group">
                                    <label>Type <strong style="font-family:var(--font-m);color:var(--sa3);">RESTORE</strong> to confirm:</label>
                                    <input type="text" class="form-control" id="uploadRestoreToken" name="confirmation_token" placeholder="Type RESTORE here" autocomplete="off">
                                </div>

                                <button type="submit" class="btn btn-danger" id="uploadRestoreBtn" disabled>
                                    <i class="fa fa-upload"></i> Upload &amp; Restore
                                </button>
                            </form>
                        </div>

                        <div class="col-md-5">
                            <div class="restore-warn" style="margin-top:30px;">
                                <i class="fa fa-info-circle" style="color:#ef4444;margin-right:6px;"></i>
                                <strong style="color:#ef4444;">Upload Restore Notes</strong><br>
                                <ul style="margin:8px 0 0;padding-left:16px;line-height:1.9;">
                                    <li>Only <code>.json</code> backup files exported from this system are supported.</li>
                                    <li>The <code>firebase_key</code> in the backup must match an existing school.</li>
                                    <li>A safety backup of current live data is created automatically.</li>
                                    <li>Uploaded file paths (if any) are not restored — only Firebase data.</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>

            </div>

        </div><!-- /tab-restore -->

    </div><!-- /tab-content -->

</section>

<!-- ── Create Backup Confirm Modal ── -->
<div class="modal fade" id="createBackupModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title"><i class="fa fa-database" style="color:var(--sa3);margin-right:8px;"></i>Create Backup</h4>
            </div>
            <div class="modal-body" style="font-size:13px;color:var(--t2);">
                <p>Create a <strong id="cbModalType"></strong> backup for <strong id="cbModalSchool"></strong>?</p>
                <p style="font-size:12px;color:var(--t3);">This may take a few seconds depending on data size.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="confirmCreateBtn">
                    <i class="fa fa-database"></i> Create Backup
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Restore Backup Modal ── -->
<div class="modal fade" id="restoreModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:rgba(239,68,68,.07);border-bottom:1px solid rgba(239,68,68,.2);">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title" style="color:#ef4444;"><i class="fa fa-exclamation-triangle" style="margin-right:8px;"></i>Restore Backup</h4>
            </div>
            <div class="modal-body">
                <div class="restore-warn" style="margin-bottom:16px;">
                    <strong style="color:#ef4444;">Warning:</strong> Restoring backup
                    <strong id="restoreBidLabel" style="font-family:var(--font-m);color:var(--sa3);"></strong>
                    for <strong id="restoreSchoolLabel"></strong> will overwrite all live Firebase data. A safety backup is created automatically.
                </div>
                <div class="form-group">
                    <label>Type <strong style="font-family:var(--font-m);color:var(--sa3);">RESTORE</strong> to confirm:</label>
                    <input type="text" class="form-control" id="restoreTokenInput" placeholder="Type RESTORE" autocomplete="off">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmRestoreBtn" disabled>
                    <i class="fa fa-history"></i> Restore Now
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ── Delete Backup Modal ── -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title"><i class="fa fa-trash" style="color:#ef4444;margin-right:8px;"></i>Delete Backup</h4>
            </div>
            <div class="modal-body">
                <p style="font-size:13px;color:var(--t2);">Permanently delete backup <strong id="deleteBidLabel" style="font-family:var(--font-m);color:var(--sa3);"></strong>? This cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn"><i class="fa fa-trash"></i> Delete</button>
            </div>
        </div>
    </div>
</div>

<script>
(function(){
/* ── Helpers ── */
function esc(s){ return $('<div>').text(s||'').html(); }
function typeBadge(type, backupType){
    var map = {
        manual:             'type-badge-manual',
        scheduled:          'type-badge-scheduled',
        pre_restore_safety: 'type-badge-safety',
    };
    var cls  = map[type] || 'label-default';
    var fmtCls = backupType === 'full' ? 'type-badge-full' : 'type-badge-firebase';
    return '<span class="label '+cls+'" style="margin-right:3px;">'+esc(type||'manual')+'</span>'
         + '<span class="label '+fmtCls+'">'+esc(backupType||'firebase')+'</span>';
}
function fmtSize(bytes){ if(!bytes) return '—'; if(bytes>=1048576) return (bytes/1048576).toFixed(2)+' MB'; if(bytes>=1024) return (bytes/1024).toFixed(1)+' KB'; return bytes+' B'; }

/* ── State ── */
var _manualSchool = '', _manualSchoolName = '';
var _restoreSchool= '', _restoreSchoolName= '';
var _pendingRestore = {bid:'', school:''};
var _pendingDelete  = {bid:'', school:''};

/* ── Load global stats ── */
function loadStats(){
    $.post(BASE_URL+'superadmin/backups/backup_stats', {}, function(r){
        if(r.status!=='success') return;
        $('#kpiTotal').text(r.total_backups);
        $('#kpiSchools').text(r.schools_backed);
        $('#kpiSize').text(r.total_size);
        $('#kpiManual').text(r.manual_count);
        $('#kpiAuto').text(r.auto_count);
    },'json');
}

/* ── Overview: recent backups (all schools) ── */
function loadOverviewBackups(){
    $.post(BASE_URL+'superadmin/backups/fetch_backups', {}, function(r){
        if(r.status!=='success'){ $('#overviewBackupsTbody').html('<tr><td colspan="8" style="text-align:center;padding:24px;color:var(--t3);">Failed to load.</td></tr>'); return; }
        var rows = (r.rows||[]).slice(0,30);
        if(!rows.length){ $('#overviewBackupsTbody').html('<tr><td colspan="8" style="text-align:center;padding:28px;color:var(--t3);">No backups found across any school.</td></tr>'); return; }
        var html = rows.map(function(b){
            return '<tr>'
              +'<td style="font-family:var(--font-m);font-size:11px;color:var(--sa3);">'+esc(b.backup_id)+'</td>'
              +'<td style="font-size:12px;">'+esc(b.school_name||b.school_uid||'')+'</td>'
              +'<td style="font-size:11.5px;color:var(--t2);">'+esc(b.created_at||'')+'</td>'
              +'<td style="font-size:11.5px;color:var(--t3);">'+esc(b.created_by||'')+'</td>'
              +'<td>'+typeBadge(b.type, b.backup_type)+'</td>'
              +'<td><span class="label label-default" style="font-family:var(--font-m);">'+esc(b.format||'json')+'</span></td>'
              +'<td style="font-family:var(--font-m);font-size:11px;color:var(--t3);">'+esc(b.size_human||fmtSize(b.size_bytes))+'</td>'
              +'<td>'
              +'<a href="'+BASE_URL+'superadmin/backups/download/'+encodeURIComponent(b.safe_uid||b.school_uid)+'/'+encodeURIComponent(b.backup_id)+'" class="btn btn-default btn-xs" title="Download"><i class="fa fa-download"></i></a>'
              +'</td>'
              +'</tr>';
        }).join('');
        $('#overviewBackupsTbody').html(html);
    },'json');
}

/* ── Manual tab: school select ── */
$('#manualSchoolSelect').on('change', function(){
    _manualSchool     = $(this).val();
    _manualSchoolName = $(this).find('option:selected').text();
    $('#createBackupBtn').prop('disabled', !_manualSchool);
    if(!_manualSchool){ $('#manualBackupsTbody').html('<tr><td colspan="7" style="text-align:center;padding:24px;color:var(--t3);">Select a school.</td></tr>'); resetSchoolStats(); return; }
    loadManualBackups();
});

$('#manualBackupType').on('change', function(){
    $('#fullBackupInfo').toggle($(this).val() === 'full');
});

$('#createBackupBtn').on('click', function(){
    if(!_manualSchool) return;
    $('#cbModalSchool').text(_manualSchoolName);
    $('#cbModalType').text($('#manualBackupType').find('option:selected').text());
    $('#createBackupModal').modal('show');
});

$('#confirmCreateBtn').on('click', function(){
    var $btn = $(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Creating...');
    $.ajax({ url: BASE_URL+'superadmin/backups/create_backup', type:'POST',
        data:{ school_uid: _manualSchool, backup_type: $('#manualBackupType').val() },
        success:function(r){
            saToast(r.message, r.status);
            if(r.status==='success'){ $('#createBackupModal').modal('hide'); loadManualBackups(); loadOverviewBackups(); loadStats(); }
        },
        error:function(){ saToast('Server error.','error'); },
        complete:function(){ $btn.prop('disabled',false).html('<i class="fa fa-database"></i> Create Backup'); }
    });
});

function loadManualBackups(){
    if(!_manualSchool) return;
    $.ajax({ url: BASE_URL+'superadmin/backups/fetch_backups', type:'POST', data:{ school_uid: _manualSchool },
        success:function(r){
            if(r.status!=='success'){ saToast(r.message,'error'); return; }
            var rows = r.rows || [];
            renderManualTable(rows);
            updateSchoolStats(rows);
            $('#manualBackupMeta').text(rows.length+' backup'+(rows.length!==1?'s':'')+' for '+_manualSchoolName);
        },
        error:function(){ saToast('Failed to load.','error'); }
    });
}

function renderManualTable(rows){
    if(!rows.length){ $('#manualBackupsTbody').html('<tr><td colspan="7" style="text-align:center;padding:28px;color:var(--t3);">No backups yet for this school.</td></tr>'); return; }
    var html = rows.map(function(b){
        return '<tr>'
          +'<td style="font-family:var(--font-m);font-size:10.5px;color:var(--sa3);">'+esc(b.backup_id)+'</td>'
          +'<td style="font-size:11.5px;color:var(--t2);">'+esc(b.created_at||'')+'</td>'
          +'<td style="font-size:11.5px;color:var(--t3);">'+esc(b.created_by||'')+'</td>'
          +'<td>'+typeBadge(b.type, b.backup_type)+'</td>'
          +'<td style="font-family:var(--font-m);font-size:11px;color:var(--t3);">'+esc(b.size_human||fmtSize(b.size_bytes))+'</td>'
          +'<td style="font-size:11px;color:var(--t3);">'+esc(b.last_restored_at||'—')+'</td>'
          +'<td>'
          +'<div style="display:flex;gap:3px;">'
          +'<a href="'+BASE_URL+'superadmin/backups/download/'+encodeURIComponent(b.school_uid||_manualSchool)+'/'+encodeURIComponent(b.backup_id)+'" class="btn btn-default btn-xs" title="Download"><i class="fa fa-download"></i></a>'
          +'<button class="btn btn-warning btn-xs manual-restore-btn" data-bid="'+esc(b.backup_id)+'" title="Restore"><i class="fa fa-history"></i></button>'
          +'<button class="btn btn-danger  btn-xs manual-delete-btn"  data-bid="'+esc(b.backup_id)+'" title="Delete"><i class="fa fa-trash"></i></button>'
          +'</div>'
          +'</td>'
          +'</tr>';
    }).join('');
    $('#manualBackupsTbody').html(html);
}

function updateSchoolStats(rows){
    var total = rows.length;
    var totalBytes = rows.reduce(function(s,r){ return s+(parseInt(r.size_bytes||0)); }, 0);
    $('#schoolStatCount').text(total);
    $('#schoolStatSize').text(fmtSize(totalBytes));
    var latest = rows[0];
    $('#schoolStatLatest').html(latest
        ? 'Latest: <strong style="color:var(--sa3);font-family:var(--font-m);">'+esc(latest.backup_id)+'</strong><br><span style="font-size:11px;">'+esc(latest.created_at||'')+'</span>'
        : 'No backups yet.');
}
function resetSchoolStats(){
    $('#schoolStatCount, #schoolStatSize').text('—');
    $('#schoolStatLatest').text('Select a school.');
}

/* ── Restore from manual table ── */
$(document).on('click', '.manual-restore-btn', function(){
    _pendingRestore = { bid: $(this).data('bid'), school: _manualSchool, name: _manualSchoolName };
    openRestoreModal();
});

/* ── Delete from manual table ── */
$(document).on('click', '.manual-delete-btn', function(){
    _pendingDelete = { bid: $(this).data('bid'), school: _manualSchool };
    $('#deleteBidLabel').text(_pendingDelete.bid);
    $('#deleteModal').modal('show');
});

$('#confirmDeleteBtn').on('click', function(){
    var $btn = $(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i>');
    $.ajax({ url: BASE_URL+'superadmin/backups/delete_backup', type:'POST',
        data:{ school_uid: _pendingDelete.school, backup_id: _pendingDelete.bid },
        success:function(r){
            saToast(r.message, r.status);
            if(r.status==='success'){ $('#deleteModal').modal('hide'); loadManualBackups(); loadOverviewBackups(); loadStats(); }
        },
        error:function(){ saToast('Server error.','error'); },
        complete:function(){ $btn.prop('disabled',false).html('<i class="fa fa-trash"></i> Delete'); }
    });
});

/* ── Restore modal (shared) ── */
function openRestoreModal(){
    $('#restoreBidLabel').text(_pendingRestore.bid);
    $('#restoreSchoolLabel').text(_pendingRestore.name||_pendingRestore.school);
    $('#restoreTokenInput').val('');
    $('#confirmRestoreBtn').prop('disabled',true);
    $('#restoreModal').modal('show');
}

$('#restoreTokenInput').on('input', function(){
    $('#confirmRestoreBtn').prop('disabled', $(this).val().trim() !== 'RESTORE');
});

$('#confirmRestoreBtn').on('click', function(){
    if($('#restoreTokenInput').val().trim() !== 'RESTORE') return;
    var $btn = $(this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Restoring...');
    $.ajax({ url: BASE_URL+'superadmin/backups/restore_backup', type:'POST',
        data:{ school_uid: _pendingRestore.school, backup_id: _pendingRestore.bid, confirmation_token: 'RESTORE' },
        success:function(r){
            saToast(r.message, r.status);
            if(r.status==='success'){ $('#restoreModal').modal('hide'); loadManualBackups(); loadOverviewBackups(); }
        },
        error:function(){ saToast('Server error.','error'); },
        complete:function(){ $btn.prop('disabled',false).html('<i class="fa fa-history"></i> Restore Now'); }
    });
});

/* ── Restore tab: from existing ── */
$('#restoreSchoolSelect').on('change', function(){
    _restoreSchool     = $(this).val();
    _restoreSchoolName = $(this).find('option:selected').text();
    $('#loadRestoreBackupsBtn').prop('disabled', !_restoreSchool);
});

$('#loadRestoreBackupsBtn').on('click', function(){
    if(!_restoreSchool) return;
    $(this).prop('disabled',true);
    $('#loadRestoreIcon').addClass('fa-spin');
    $.ajax({ url: BASE_URL+'superadmin/backups/fetch_backups', type:'POST', data:{ school_uid: _restoreSchool },
        success:function(r){
            if(r.status!=='success'){ saToast(r.message,'error'); return; }
            var rows = r.rows || [];
            if(!rows.length){ $('#restoreBackupsTbody').html('<tr><td colspan="6" style="text-align:center;padding:24px;color:var(--t3);">No backups for this school.</td></tr>'); return; }
            var html = rows.map(function(b){
                return '<tr>'
                  +'<td style="font-family:var(--font-m);font-size:10.5px;color:var(--sa3);">'+esc(b.backup_id)+'</td>'
                  +'<td style="font-size:11.5px;color:var(--t2);">'+esc(b.created_at||'')+'</td>'
                  +'<td>'+typeBadge(b.type, b.backup_type)+'</td>'
                  +'<td style="font-family:var(--font-m);font-size:11px;color:var(--t3);">'+esc(b.size_human||fmtSize(b.size_bytes))+'</td>'
                  +'<td style="font-size:11px;color:var(--t3);">'+esc(b.last_restored_at||'—')+'</td>'
                  +'<td>'
                  +'<button class="btn btn-warning btn-xs restore-tab-btn" data-bid="'+esc(b.backup_id)+'"><i class="fa fa-history"></i> Restore</button>'
                  +'</td>'
                  +'</tr>';
            }).join('');
            $('#restoreBackupsTbody').html(html);
        },
        error:function(){ saToast('Failed to load.','error'); },
        complete:function(){ $('#loadRestoreBackupsBtn').prop('disabled',false); $('#loadRestoreIcon').removeClass('fa-spin'); }
    });
});

$(document).on('click', '.restore-tab-btn', function(){
    _pendingRestore = { bid: $(this).data('bid'), school: _restoreSchool, name: _restoreSchoolName };
    openRestoreModal();
});

/* ── Upload & Restore ── */
$('#uploadDropZone').on('click', function(){ $('#backupFileInput').trigger('click'); });
$('#uploadDropZone').on('dragover', function(e){ e.preventDefault(); $(this).css('border-color','var(--sa3)'); });
$('#uploadDropZone').on('dragleave', function(){ $(this).css('border-color','var(--border)'); });
$('#uploadDropZone').on('drop', function(e){
    e.preventDefault(); $(this).css('border-color','var(--border)');
    var files = e.originalEvent.dataTransfer.files;
    if(files.length) { $('#backupFileInput')[0].files = files; $('#backupFileInput').trigger('change'); }
});

$('#backupFileInput').on('change', function(){
    var file = this.files[0];
    if(!file){ $('#uploadFileInfo').text(''); $('#uploadPreviewBox').hide(); return; }
    $('#uploadFileInfo').html('<i class="fa fa-file-code-o" style="color:var(--sa3);margin-right:5px;"></i>'+esc(file.name)+' ('+fmtSize(file.size)+')');
    var reader = new FileReader();
    reader.onload = function(e){
        try {
            var data = JSON.parse(e.target.result);
            var html = '<div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:12px;">'
                + '<div><span style="color:var(--t3);">School:</span> <strong>'+esc(data.school_name||'—')+'</strong></div>'
                + '<div><span style="color:var(--t3);">Firebase Key:</span> <strong style="font-family:var(--font-m);">'+esc(data.firebase_key||'—')+'</strong></div>'
                + '<div><span style="color:var(--t3);">Backed Up At:</span> <strong>'+esc(data.backed_up_at||'—')+'</strong></div>'
                + '<div><span style="color:var(--t3);">Type:</span> <strong>'+esc(data.backup_type||'firebase')+'</strong></div>'
                + '<div><span style="color:var(--t3);">Format:</span> <strong>'+esc(data.backup_format||'1.0')+'</strong></div>'
                + '<div><span style="color:var(--t3);">Schools key:</span> '+(data.Schools ? '<span class="label label-success">Present</span>' : '<span class="label label-danger">Missing!</span>')+'</div>'
                + '</div>';
            $('#uploadPreviewContent').html(html);
            $('#uploadPreviewBox').show();
        } catch(ex){ $('#uploadPreviewContent').html('<span style="color:#ef4444;">Invalid JSON file.</span>'); $('#uploadPreviewBox').show(); }
    };
    reader.readAsText(file);
});

$('#uploadRestoreToken').on('input', function(){
    var hasFile = $('#backupFileInput').val() !== '';
    $('#uploadRestoreBtn').prop('disabled', $(this).val().trim() !== 'RESTORE' || !hasFile);
});

$('#uploadRestoreForm').on('submit', function(e){
    e.preventDefault();
    if($('#uploadRestoreToken').val().trim() !== 'RESTORE') return;
    var $btn  = $('#uploadRestoreBtn').prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Restoring...');
    var fd    = new FormData(this);
    $.ajax({
        url: BASE_URL+'superadmin/backups/upload_restore',
        type: 'POST', data: fd, processData: false, contentType: false,
        success:function(r){
            saToast(r.message, r.status);
            if(r.status==='success'){
                $('#uploadRestoreToken').val('');
                $('#backupFileInput').val('');
                $('#uploadFileInfo').text('');
                $('#uploadPreviewBox').hide();
                $btn.prop('disabled',true).html('<i class="fa fa-upload"></i> Upload &amp; Restore');
                loadOverviewBackups(); loadStats();
            } else {
                $btn.prop('disabled',false).html('<i class="fa fa-upload"></i> Upload &amp; Restore');
            }
        },
        error:function(){ saToast('Server error.','error'); $btn.prop('disabled',false).html('<i class="fa fa-upload"></i> Upload &amp; Restore'); }
    });
});

/* ── Schedule form ── */
$('#schedFrequency').on('change', function(){
    $('#dayOfWeekGroup').toggle($(this).val() === 'weekly');
});

// Toggle switch
$('#schedToggleTrack').on('click', function(){
    var chk = $('#schedEnabled');
    var checked = !chk.prop('checked');
    chk.prop('checked', checked);
    $(this).css('background', checked ? 'var(--sa)' : 'var(--border)');
    $('#schedToggleThumb').css('left', checked ? '23px' : '3px');
});

$('#scheduleForm').on('submit', function(e){
    e.preventDefault();
    var $btn  = $('[type=submit]', this).prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Saving...');
    var data  = $(this).serializeArray();
    data.push({ name:'enabled', value: $('#schedEnabled').prop('checked') ? 1 : 0 });
    $.ajax({ url: BASE_URL+'superadmin/backups/save_schedule', type:'POST', data: data,
        success:function(r){
            saToast(r.message, r.status);
        },
        error:function(){ saToast('Server error.','error'); },
        complete:function(){ $btn.prop('disabled',false).html('<i class="fa fa-save"></i> Save Schedule'); }
    });
});

/* ── Run scheduled now (both buttons) ── */
function runNow($btn){
    $btn.prop('disabled',true).html('<i class="fa fa-spinner fa-spin"></i> Running backup for all schools...');
    $.ajax({ url: BASE_URL+'superadmin/backups/run_scheduled_now', type:'POST', timeout: 300000,
        success:function(r){
            saToast(r.message, r.status);
            if(r.status==='success'){
                // Show results table
                var html = '<table class="table" style="font-size:12.5px;margin:0;">'
                    + '<thead><tr><th>School</th><th>Status</th><th>Backup ID</th><th>Size</th><th>Message</th></tr></thead><tbody>';
                (r.results||[]).forEach(function(row){
                    html += '<tr>'
                      +'<td>'+esc(row.school)+'</td>'
                      +'<td><span class="label '+(row.status==='ok'?'label-success':'label-danger')+'">'+esc(row.status)+'</span></td>'
                      +'<td style="font-family:var(--font-m);font-size:11px;">'+esc(row.backup_id||'—')+'</td>'
                      +'<td style="font-family:var(--font-m);font-size:11px;">'+esc(row.size||'—')+'</td>'
                      +'<td style="font-size:11px;color:var(--t3);">'+esc(row.message||'')+'</td>'
                      +'</tr>';
                });
                html += '</tbody></table>';
                $('#runResultBody').html(html);
                $('#runResultPanel').show();
                $('#backupTabs a[href="#tab-schedule"]').tab('show');
                loadOverviewBackups(); loadStats();
            }
        },
        error:function(){ saToast('Run failed or timed out.','error'); },
        complete:function(){ $btn.prop('disabled',false).html('<i class="fa fa-play"></i> Run All Schools Now'); }
    });
}

$('#runNowBtnOverview').on('click', function(){ runNow($(this)); });
$('#runNowBtnSchedule').on('click', function(){ runNow($(this)); });

/* ── Init ── */
$(function(){
    loadStats();
    loadOverviewBackups();
});

})();
</script>
