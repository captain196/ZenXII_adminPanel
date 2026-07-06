<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html>
<head>
    <!-- Set the theme on <html> BEFORE any CSS paints, so pages never flash the
         default (night) palette on navigation. Mirrors applyTheme() below. -->
    <script>(function(){try{var t=localStorage.getItem('graderiq_theme');if(!t){var h=new Date().getHours();t=(h>=6&&h<18)?'day':'night';}document.documentElement.setAttribute('data-theme',t);}catch(e){}})();</script>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>ZenXii Admin</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <!-- CSRF tokens — must be before any scripts -->
    <meta name="csrf-token" content="<?= $this->security->get_csrf_hash() ?>">
    <meta name="csrf-name"  content="<?= $this->security->get_csrf_token_name() ?>">

    <!-- Stylesheets -->
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/bower_components/bootstrap/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/bower_components/font-awesome/css/font-awesome.min.css">
    <!-- PERF: Ionicons removed — 0 real `ion ion-*` usages in any view; the app uses
         Font Awesome for icons. Saves ~50KB render-blocking CSS + the Ionicons webfont.
         (Re-add this line if a missing glyph ever appears.) -->
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/dist/css/AdminLTE.min.css">
    <!-- PERF: only skin-blue is ever used (see body class below); load just that one
         skin instead of _all-skins (which bundles every unused red/green/purple/black
         variant) to cut ~39KB of render-blocking CSS off every page. -->
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/dist/css/skins/skin-blue.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/2.0.8/css/dataTables.dataTables.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/buttons/3.0.2/css/buttons.dataTables.css">
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/bower_components/morris.js/morris.css">
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/bower_components/bootstrap-datepicker/dist/css/bootstrap-datepicker.min.css">
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/bower_components/bootstrap-daterangepicker/daterangepicker.css">
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/css/style.css">
    <!-- PERF: preconnect so the DNS+TLS handshake to Google Fonts starts before the
         stylesheet is parsed, shaving the font round-trip off the critical path. -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&family=Syne:wght@600;700;800&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?php echo base_url(); ?>tools/css/header-inline.css"><!-- inline header CSS externalized (QW5) -->

    <!-- Global JS variables — available on every page that loads this header -->
    <script>
        var BASE_URL  = '<?= base_url() ?>';
        var csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
        var csrfName  = document.querySelector('meta[name="csrf-name"]').getAttribute('content');

        // PERF: shared debounce/throttle so list-filter inputs stop running an
        // O(rows) DOM scan on every keystroke (UX audit). Preserves `this` + args,
        // so it wraps both vanilla and delegated jQuery handlers unchanged.
        window.ZXutil = window.ZXutil || {
            debounce: function (fn, wait) {
                var t; wait = (wait == null ? 180 : wait);
                return function () {
                    var ctx = this, args = arguments;
                    clearTimeout(t);
                    t = setTimeout(function () { fn.apply(ctx, args); }, wait);
                };
            }
        };

        // Auto-attach CSRF to ALL jQuery $.ajax() and $.post() calls project-wide
        document.addEventListener('DOMContentLoaded', function () {
            if (typeof $ !== 'undefined') {
                $.ajaxSetup({
                    beforeSend: function (xhr, settings) {
                        if (settings.type === 'POST' || settings.type === 'post') {
                            xhr.setRequestHeader('X-CSRF-Token', csrfToken);
                            if (typeof settings.data === 'string' && settings.data.length > 0) {
                                settings.data += '&' + csrfName + '=' + encodeURIComponent(csrfToken);
                            } else if (settings.data instanceof FormData) {
                                // FormData (file uploads, multipart forms) — must use .append()
                                // NOT settings.data[key] = val (that just sets a JS property)
                                if (!settings.data.has(csrfName)) {
                                    settings.data.append(csrfName, csrfToken);
                                }
                            } else if (typeof settings.data === 'object' && settings.data !== null) {
                                settings.data[csrfName] = csrfToken;
                            } else {
                                settings.data = csrfName + '=' + encodeURIComponent(csrfToken);
                            }
                        }
                    }
                });
            }
        });

        // Also patch native fetch() so POST requests carry the CSRF token both
        // in the header (for MY_Controller fallback) and in the body string
        // (for CI's built-in csrf_protection which reads $_POST, not headers).
        (function () {
            var _fetch = window.fetch;
            window.fetch = function (input, init) {
                init = init || {};
                if ((init.method || 'GET').toUpperCase() === 'POST') {
                    // 1. Add to request header
                    if (init.headers instanceof Headers) {
                        if (!init.headers.has('X-CSRF-Token')) {
                            init.headers.set('X-CSRF-Token', csrfToken);
                        }
                    } else {
                        init.headers = init.headers || {};
                        init.headers['X-CSRF-Token'] = init.headers['X-CSRF-Token'] || csrfToken;
                    }
                    // 2. Also inject into POST body string so CI's built-in
                    //    csrf_protection (which reads $_POST) can verify it.
                    if (typeof init.body === 'string' &&
                        init.body.indexOf(csrfName + '=') === -1) {
                        init.body += '&' + csrfName + '=' + encodeURIComponent(csrfToken);
                    }
                }
                return _fetch.call(this, input, init);
            };
        }());
    </script>
</head>


<body class="hold-transition skin-blue sidebar-mini">
<script>
  /* Restore persisted sidebar-collapse state before paint (desktop only —
     on mobile AdminLTE uses the off-canvas 'sidebar-open' model instead). */
  try {
    if (window.innerWidth > 767 && localStorage.getItem('graderiq_sidebar') === 'collapsed') {
      document.body.classList.add('sidebar-collapse');
    }
  } catch (e) {}
</script>
<div class="wrapper">

<?php $this->load->view('include/zenxii_loader'); ?>

<!-- ═══════════════════ TOP NAVBAR ═══════════════════ -->
<header class="main-header">
    <div class="logo">
        <a href="<?= base_url('admin') ?>" class="g-logo-link">
            <img src="<?= base_url('Designs/zenxii_logo_2.png') ?>" alt="ZenXii" class="g-mark">
            <div class="g-logotext">
                <div class="g-logoname">Zen<b>Xii</b></div>
                <div class="g-logosub">
                    <span class="g-school-name"><?= isset($school_display_name) ? strtoupper(htmlspecialchars($school_display_name, ENT_QUOTES, 'UTF-8')) : (isset($school_name) ? strtoupper(htmlspecialchars($school_name, ENT_QUOTES, 'UTF-8')) : 'SCHOOL ERP') ?></span><?= isset($session_year) ? ' · ' . htmlspecialchars($session_year, ENT_QUOTES, 'UTF-8') : '' ?>
                </div>
            </div>
        </a>
        <a href="#" class="sidebar-toggle" data-toggle="push-menu" role="button"
           aria-label="Collapse sidebar" aria-expanded="true" title="Collapse sidebar">
            <!-- Professional sidebar-panel icon (Lucide-style inline SVG, crisp at
                 any size): the rounded frame + left rail reads as "the sidebar"; a
                 chevron shows the action — ‹ collapse when open, › expand when
                 collapsed. Mobile shows a clean menu glyph (off-canvas drawer). -->
            <svg class="sb-ico sb-ico-collapse" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2.4"/><path d="M9 3v18"/><path d="m16 15-3-3 3-3"/></svg>
            <svg class="sb-ico sb-ico-expand" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="2.4"/><path d="M9 3v18"/><path d="m14 9 3 3-3 3"/></svg>
            <svg class="sb-ico sb-ico-mobile" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.1" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 6h16"/><path d="M4 12h16"/><path d="M4 18h16"/></svg>
        </a>
    </div>

    <nav class="navbar navbar-static-top">

        <div class="g-search">
            <i class="fa fa-search"></i>
            <input type="text" id="globalSearch" placeholder="Search anything…" autocomplete="off">
        </div>

        <div class="navbar-custom-menu">
            <div class="g-actions">

                <button class="g-theme-pill" id="themeToggle" title="Toggle day/night theme">
                    <div class="g-track"><div class="g-knob"></div></div>
                    <i id="themeIcon" class="fa fa-moon-o" style="font-size:11px;"></i>
                    <span id="themeLabel">Night</span>
                </button>

                <div class="g-bell-wrap" id="gBellWrap">
                    <button class="g-ibtn" id="gBellBtn" title="Notices">
                        <i class="fa fa-bell-o"></i>
                        <span class="g-dot" id="gBadge" data-n="0">0</span>
                    </button>
                    <div class="g-bell-panel" id="gBellPanel">
                        <div class="g-bell-hd">
                            <div class="g-bell-tabs">
                                <button class="g-bell-tab active" data-tab="notices" onclick="gSwitchBellTab('notices',this)">Notices</button>
                                <button class="g-bell-tab" data-tab="tasks" onclick="gSwitchBellTab('tasks',this)">
                                    Tasks <span class="g-bell-tab-badge" id="gTaskBadge" style="display:none">0</span>
                                </button>
                            </div>
                            <button class="g-bell-mark-btn" onclick="gMarkAllRead()">✓ Mark all read</button>
                        </div>
                        <div class="g-bell-list" id="gBellList">
                            <div class="g-bell-empty"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
                        </div>
                        <div class="g-bell-list" id="gTaskList" style="display:none">
                            <div class="g-bell-empty"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
                        </div>
                        <div class="g-bell-ft" id="gBellFtNotices">
                            <a href="<?= base_url('communication/notices') ?>">
                                <i class="fa fa-list-ul" style="margin-right:5px"></i>View All Notices
                            </a>
                        </div>
                        <div class="g-bell-ft" id="gBellFtTasks" style="display:none">
                            <a href="<?= base_url('admin') ?>">
                                <i class="fa fa-tachometer" style="margin-right:5px"></i>View Dashboard
                            </a>
                        </div>
                    </div>
                </div>

                <!-- ── Session Switcher ──────────────────────────────────── -->
                <div class="g-sess-wrap" id="gSessWrap">
                    <button class="g-ibtn g-sess-btn" id="gSessBtn" title="Switch Academic Session"
                            style="width:auto;padding:0 10px;gap:5px;font-size:12px;font-family:var(--font-m);">
                        <i class="fa fa-calendar-o"></i>
                        <span id="gSessLabel"><?= htmlspecialchars($session_year ?? '', ENT_QUOTES, 'UTF-8') ?></span>
                        <i class="fa fa-chevron-down" style="font-size:9px;opacity:.5;"></i>
                    </button>
                    <div class="g-sess-panel" id="gSessPanel">
                        <div class="g-sess-hd">Academic Session</div>
                        <ul class="g-sess-list">
                            <?php foreach ($available_sessions ?? [] as $yr):
                                $isActive = ($yr === ($session_year ?? ''));
                            ?>
                            <li class="g-sess-item<?= $isActive ? ' g-sess-item--active' : '' ?>"
                                data-year="<?= htmlspecialchars($yr, ENT_QUOTES, 'UTF-8') ?>">
                                <i class="fa fa-check g-sess-check"></i>
                                <?= htmlspecialchars($yr, ENT_QUOTES, 'UTF-8') ?>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (in_array(($admin_role ?? ''), ['Super Admin', 'School Super Admin'])): ?>
                        <div class="g-sess-ft">
                            <button class="g-sess-new-btn" id="gSessNewBtn">
                                <i class="fa fa-plus"></i> New Session
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <ul class="nav navbar-nav" style="list-style:none;margin:0;padding:0">
                    <li class="dropdown user user-menu">
                        <a href="#" class="dropdown-toggle" data-toggle="dropdown">
                            <img src="<?= base_url() ?>tools/dist/img/user2-160x160.jpg" class="user-image" alt="">
                            <span class="hidden-xs"><?= htmlspecialchars($admin_name ?? 'Admin', ENT_QUOTES, 'UTF-8') ?></span>
                            <i class="fa fa-angle-down" style="font-size:10px;opacity:.4;margin-left:3px"></i>
                        </a>
                        <ul class="dropdown-menu dropdown-menu-right">
                            <li class="user-header">
                                <img src="<?= base_url() ?>tools/dist/img/user2-160x160.jpg" class="img-circle" style="width:52px;height:52px" alt="">
                                <p><?= htmlspecialchars($admin_name ?? 'Admin', ENT_QUOTES, 'UTF-8') ?><small><?= htmlspecialchars($admin_role ?? '', ENT_QUOTES, 'UTF-8') ?></small></p>
                            </li>
                            <li class="user-footer">
                                <div><a href="<?= base_url('admin/profile') ?>" class="btn btn-flat"><i class="fa fa-user" style="margin-right:5px"></i>Profile</a></div>
                                <div><a href="<?= base_url('admin_login/logout') ?>" class="btn btn-flat"><i class="fa fa-sign-out" style="margin-right:5px"></i>Logout</a></div>
                            </li>
                        </ul>
                    </li>
                </ul>

            </div>
        </div>
    </nav>
</header>

<!-- ── Create Session Modal — at body level so Bootstrap stacking works ── -->
<?php if (in_array(($admin_role ?? ''), ['Super Admin', 'School Super Admin'])): ?>
<div class="modal fade" id="gCreateSessModal" tabindex="-1" role="dialog">
    <div class="modal-dialog modal-sm modal-dialog-centered" role="document">
        <div class="modal-content">
            <div class="modal-header">
                <button type="button" class="close" data-dismiss="modal">&times;</button>
                <h4 class="modal-title">Create New Session</h4>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label>Session Year <small class="text-muted">(YYYY-YY format)</small></label>
                    <input type="text" id="gNewSessInput" class="form-control"
                           placeholder="e.g. 2026-27" maxlength="7">
                    <small class="help-block text-muted" id="gNewSessHint"></small>
                </div>
                <div id="gCreateSessError" class="alert alert-danger"
                     style="display:none;margin-bottom:0;"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-default btn-app btn-app--secondary" data-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-success btn-app btn-app--success" id="gCreateSessSubmit">
                    <i class="fa fa-plus"></i> Create &amp; Switch
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ═══════════════════ SUBSCRIPTION WARNING BANNER ═══════════════════ -->
<?php if (!empty($subscription_warning)): ?>
<div id="subWarnBanner" style="
    position:fixed;top:var(--hh);left:0;right:0;z-index:1039;
    background:linear-gradient(90deg,#053d38,#0a5c55,#053d38);
    border-bottom:1px solid rgba(15,118,110,.4);
    padding:8px 20px;display:flex;align-items:center;gap:10px;
    font-family:var(--font-b);font-size:12.5px;color:#b2e0da;
    box-shadow:0 2px 12px rgba(0,0,0,.35);">
    <i class="fa fa-exclamation-triangle" style="color:#14b8a6;font-size:14px;flex-shrink:0;"></i>
    <span style="flex:1;"><?= htmlspecialchars($subscription_warning, ENT_QUOTES, 'UTF-8') ?></span>
    <a href="<?= base_url('admin_login/logout') ?>"
       style="background:rgba(15,118,110,.25);border:1px solid rgba(15,118,110,.5);color:#14b8a6;
              padding:3px 10px;border-radius:6px;font-size:11.5px;font-weight:700;white-space:nowrap;
              text-decoration:none;">
        Renew Now
    </a>
    <button onclick="document.getElementById('subWarnBanner').style.display='none'"
        style="background:none;border:none;color:#ffe9a0;opacity:.6;cursor:pointer;
               font-size:16px;line-height:1;padding:0;flex-shrink:0;"
        title="Dismiss">×</button>
</div>
<style>
    /* Push content down by the banner height (~38px) when warning is visible */
    #subWarnBanner ~ .main-sidebar { top: calc(var(--hh) + 38px) !important; }
    #subWarnBanner ~ * .content-wrapper,
    .sub-warn-offset .content-wrapper { margin-top: calc(var(--hh) + 38px) !important; }
</style>
<script>
    // Shift sidebar + content-wrapper down when banner is present
    document.addEventListener('DOMContentLoaded', function() {
        var banner = document.getElementById('subWarnBanner');
        if (!banner) return;
        var hh = parseInt(getComputedStyle(document.documentElement).getPropertyValue('--hh')) || 58;
        var bh = banner.offsetHeight;
        var sidebar = document.querySelector('.main-sidebar');
        var cw = document.querySelector('.content-wrapper');
        if (sidebar) sidebar.style.top = (hh + bh) + 'px';
        if (cw) cw.style.marginTop = (hh + bh) + 'px';
        // Re-apply if dismissed
        banner.querySelector('button').addEventListener('click', function() {
            if (sidebar) sidebar.style.top = hh + 'px';
            if (cw) cw.style.marginTop = hh + 'px';
        });
    });
</script>
<?php endif; ?>

<!-- ═══════════════════ SIDEBAR ═══════════════════ -->
<aside class="main-sidebar">
    <section class="sidebar">
        <?php
        // ── RBAC sidebar helper ──────────────────────────────────────
        // $rbac_permissions is shared by MY_Controller. Bypass roles
        // (Super Admin, Admin) already have all modules in the array.
        $rp = isset($rbac_permissions) && is_array($rbac_permissions) ? $rbac_permissions : [];
        $can = function(string $module) use ($rp, $admin_role) {
            // Bypass roles always see everything
            if (isset($admin_role) && in_array($admin_role, ['Super Admin', 'School Super Admin', 'Admin'], true)) return true;
            return in_array($module, $rp, true);
        };
        ?>
        <ul class="sidebar-menu" data-widget="tree">

            <!-- ═══════════════════════════════════════════════════════════
                 STEP 0 — OVERVIEW
                 ═══════════════════════════════════════════════════════════ -->
            <li class="g-sec">Overview</li>
            <li class="sidebar-single">
                <a href="<?= base_url('admin') ?>"><i class="fa fa-th-large"></i><span>Dashboard</span></a>
            </li>

            <!-- ═══════════════════════════════════════════════════════════
                 STEP 1 — SCHOOL SETUP (configure first)
                 School Profile → Academic Setup (classes, sections, subjects, sessions) → Admission Payment
                 ═══════════════════════════════════════════════════════════ -->
            <?php if (isset($school_features) && in_array('School Management', $school_features) && $can('Configuration')): ?>
            <li class="g-sec">School Setup</li>
            <li class="sidebar-single">
                <a href="<?= base_url('schools/schoolProfile') ?>"><i class="fa fa-building-o"></i><span>School Profile</span></a>
            </li>
            <li class="sidebar-single">
                <a href="<?= base_url('school_config') ?>"><i class="fa fa-university"></i><span>Academic Setup</span></a>
            </li>
            <li class="sidebar-single">
                <a href="<?= base_url('school_config/admission_payment') ?>"><i class="fa fa-credit-card"></i><span>Admission Payment</span></a>
            </li>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════
                 STEP 2 — STAFF (hire teachers and admin staff first)
                 Staff list → New Staff → HR & Payroll (departments, recruitment, leave, payroll, appraisals)
                 ═══════════════════════════════════════════════════════════ -->
            <?php if (isset($school_features) && in_array('Staff Management', $school_features) && ($can('HR') || $can('SIS'))): ?>
            <li class="g-sec">Staff</li>
            <?php if ($can('HR')): ?>
            <li class="sidebar-single">
                <a href="<?= base_url('org') ?>"><i class="fa fa-sitemap"></i><span>Departments &amp; Roles</span></a>
            </li>
            <?php endif; ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-user-o"></i><span>Staff Records</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('staff/all_staff') ?>"><i class="fa fa-circle-o"></i>All Staff</a></li>
                    <li><a href="<?= base_url('staff/new_staff') ?>"><i class="fa fa-circle-o"></i>New Staff</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Staff Management', $school_features) && $can('HR')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-id-card-o"></i><span>HR &amp; Payroll</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('hr') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('hr/departments') ?>"><i class="fa fa-circle-o"></i>Departments</a></li>
                    <li><a href="<?= base_url('hr/recruitment') ?>"><i class="fa fa-circle-o"></i>Recruitment</a></li>
                    <li><a href="<?= base_url('ats') ?>"><i class="fa fa-circle-o"></i>Applicant Tracking</a></li>
                    <li><a href="<?= base_url('hr/leaves') ?>"><i class="fa fa-circle-o"></i>Leave Management</a></li>
                    <li><a href="<?= base_url('hr/payroll') ?>"><i class="fa fa-circle-o"></i>Payroll</a></li>
                    <li><a href="<?= base_url('hr/appraisals') ?>"><i class="fa fa-circle-o"></i>Appraisals</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════
                 STEP 3 — STUDENTS (admit students and manage records)
                 Admission CRM → Student Info System → Certificates
                 ═══════════════════════════════════════════════════════════ -->
            <?php if (isset($school_features) && in_array('Student Management', $school_features) && $can('SIS')): ?>
            <li class="g-sec">Students</li>
            <li class="treeview">
                <a href="#"><i class="fa fa-filter"></i><span>Admission CRM</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('sis/crm') ?>"><i class="fa fa-circle-o"></i>CRM Dashboard</a></li>
                    <li><a href="<?= base_url('sis/admission_leads') ?>"><i class="fa fa-circle-o"></i>Admission Leads</a></li>
                    <li><a href="<?= base_url('sis/inquiries') ?>"><i class="fa fa-circle-o"></i>Inquiries</a></li>
                    <li><a href="<?= base_url('sis/applications') ?>"><i class="fa fa-circle-o"></i>Applications</a></li>
                    <li><a href="<?= base_url('sis/pipeline') ?>"><i class="fa fa-circle-o"></i>Pipeline</a></li>
                    <li><a href="<?= base_url('sis/waitlist') ?>"><i class="fa fa-circle-o"></i>Waiting List</a></li>
                    <li><a href="<?= base_url('sis/crm_settings') ?>"><i class="fa fa-circle-o"></i>CRM Settings</a></li>
                    <li><a href="<?= base_url('sis/admission_analytics') ?>"><i class="fa fa-circle-o"></i>Analytics</a></li>
                </ul>
            </li>
            <li class="treeview">
                <a href="#"><i class="fa fa-id-badge"></i><span>Student Info System</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('sis') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('sis/students') ?>"><i class="fa fa-circle-o"></i>Student Records</a></li>
                    <li><a href="<?= base_url('sis/studentAdmission') ?>"><i class="fa fa-circle-o"></i>Admission</a></li>
                    <li><a href="<?= base_url('sis/master_student') ?>"><i class="fa fa-circle-o"></i>Import Students</a></li>
                    <li><a href="<?= base_url('sis/id_card') ?>"><i class="fa fa-circle-o"></i>ID Cards</a></li>
                    <li><a href="<?= base_url('sis/promote') ?>"><i class="fa fa-circle-o"></i>Promotion</a></li>
                    <li><a href="<?= base_url('sis/tc') ?>"><i class="fa fa-circle-o"></i>Transfer Certificates</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if ($can('Certificates')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-certificate"></i><span>Certificates</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('certificates') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('certificates/templates') ?>"><i class="fa fa-circle-o"></i>Templates</a></li>
                    <li><a href="<?= base_url('certificates/generate') ?>"><i class="fa fa-circle-o"></i>Generate</a></li>
                    <li><a href="<?= base_url('certificates/issued') ?>"><i class="fa fa-circle-o"></i>Issued</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════
                 STEP 4 — ACADEMICS (assign teachers, build timetable, run exams)
                 Academic Planner (subject-teacher allocation, timetable) → LMS → Examinations → Results
                 ═══════════════════════════════════════════════════════════ -->
            <?php if (isset($school_features) && (in_array('Class Management',$school_features)||in_array('Subject Management',$school_features)||in_array('Exam Management',$school_features)) && ($can('Academic') || $can('LMS') || $can('Examinations') || $can('Results'))): ?>
            <li class="g-sec">Academics</li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Class Management', $school_features) && $can('Academic')): ?>
            <li class="sidebar-single"><a href="<?= base_url('academic') ?>"><i class="fa fa-university"></i><span>Academic Planner</span></a></li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Class Management', $school_features) && $can('LMS')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-laptop"></i><span>LMS</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('lms') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('lms/classes') ?>"><i class="fa fa-circle-o"></i>Online Classes</a></li>
                    <li><a href="<?= base_url('lms/materials') ?>"><i class="fa fa-circle-o"></i>Study Materials</a></li>
                    <li><a href="<?= base_url('lms/assignments') ?>"><i class="fa fa-circle-o"></i>Assignments</a></li>
                    <li><a href="<?= base_url('lms/quizzes') ?>"><i class="fa fa-circle-o"></i>Quizzes</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if ($can('Homework')): ?>
            <li class="sidebar-single"><a href="<?= base_url('homework') ?>"><i class="fa fa-book"></i><span>Homework</span></a></li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Exam Management', $school_features) && $can('Examinations')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-pencil-square-o"></i><span>Examinations</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('examination') ?>"><i class="fa fa-circle-o"></i>Exam Dashboard</a></li>
                    <li><a href="<?= base_url('exam') ?>"><i class="fa fa-circle-o"></i>Manage Exams</a></li>
                    <li><a href="<?= base_url('examination/tabulation') ?>"><i class="fa fa-circle-o"></i>Tabulation Sheet</a></li>
                    <li><a href="<?= base_url('examination/merit_list') ?>"><i class="fa fa-circle-o"></i>Merit Lists</a></li>
                    <li><a href="<?= base_url('examination/analytics') ?>"><i class="fa fa-circle-o"></i>Analytics</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Exam Management', $school_features) && $can('Results')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-bar-chart"></i><span>Results</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('result') ?>"><i class="fa fa-circle-o"></i>Results Hub</a></li>
                    <li><a href="<?= base_url('result/template_designer') ?>"><i class="fa fa-circle-o"></i>Template Designer</a></li>
                    <li><a href="<?= base_url('result/marks_entry') ?>"><i class="fa fa-circle-o"></i>Marks Entry</a></li>
                    <li><a href="<?= base_url('result/class_result') ?>"><i class="fa fa-circle-o"></i>Class Results</a></li>
                    <li><a href="<?= base_url('result/cumulative') ?>"><i class="fa fa-circle-o"></i>Cumulative</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════
                 STEP 5 — DAILY OPERATIONS (attendance, red flags)
                 ═══════════════════════════════════════════════════════════ -->
            <?php if (isset($school_features) && in_array('Student Management', $school_features) && $can('Attendance')): ?>
            <li class="g-sec">Daily Operations</li>
            <li class="treeview">
                <a href="#"><i class="fa fa-calendar-check-o"></i><span>Attendance</span>
                    <span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span>
                </a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('attendance') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('attendance/student') ?>"><i class="fa fa-circle-o"></i>Student Attendance</a></li>
                    <li><a href="<?= base_url('attendance/staff') ?>"><i class="fa fa-circle-o"></i>Staff Attendance</a></li>
                    <li><a href="<?= base_url('attendance/scan') ?>"><i class="fa fa-circle-o"></i>QR Scan</a></li>
                    <li><a href="<?= base_url('attendance/analytics') ?>"><i class="fa fa-circle-o"></i>Analytics</a></li>
                    <li><a href="<?= base_url('attendance/student_leaves') ?>"><i class="fa fa-circle-o"></i>Student Leave</a></li>
                    <li><a href="<?= base_url('attendance/settings') ?>"><i class="fa fa-circle-o"></i>Settings</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if ($can('Red Flags')): ?>
            <li class="sidebar-single"><a href="<?= base_url('red_flags') ?>"><i class="fa fa-flag"></i><span>Red Flags</span></a></li>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════
                 FINANCE — fees, accounts, accounting
                 ═══════════════════════════════════════════════════════════ -->
            <?php if (isset($school_features) && (in_array('Fees Management',$school_features)||in_array('Account Management',$school_features)) && ($can('Fees') || $can('Accounting'))): ?>
            <li class="g-sec">Finance</li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Fees Management', $school_features) && $can('Fees')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-inr"></i><span>Fees</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('fees/dashboard') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>

                    <li class="g-sub-sec">Setup</li>
                    <li><a href="<?= base_url('fee_management/categories') ?>"><i class="fa fa-circle-o"></i>Titles &amp; Categories</a></li>
                    <li><a href="<?= base_url('fees/fees_chart') ?>"><i class="fa fa-circle-o"></i>Fee Chart</a></li>

                    <li class="g-sub-sec">Billing</li>
                    <li><a href="<?= base_url('fees/generate_demands') ?>"><i class="fa fa-circle-o"></i>Generate Demands</a></li>
                    <li><a href="<?= base_url('fees/fees_counter') ?>"><i class="fa fa-circle-o"></i>Fee Counter</a></li>
                    <li><a href="<?= base_url('fees/fees_records') ?>"><i class="fa fa-circle-o"></i>Records</a></li>
                    <li><a href="<?= base_url('fees/student_ledger') ?>"><i class="fa fa-circle-o"></i>Student Ledger</a></li>

                    <li class="g-sub-sec">Follow-up</li>
                    <li><a href="<?= base_url('fees/defaulter_report') ?>"><i class="fa fa-circle-o"></i>Defaulters</a></li>
                    <li><a href="<?= base_url('fee_management/reminders') ?>"><i class="fa fa-circle-o"></i>Reminders</a></li>
                    <li><a href="<?= base_url('fee_management/discounts') ?>"><i class="fa fa-circle-o"></i>Discounts &amp; Scholarships</a></li>
                    <?php $this->config->load('fees_exemption_v2_flags', true); ?>
                    <?php if ($this->config->item('CONCESSION_UI_ENABLED', 'fees_exemption_v2_flags') || $this->config->item('SERVICE_ENROLLMENT_UI_ENABLED', 'fees_exemption_v2_flags')): ?>
                    <li><a href="<?= base_url('fee_concessions') ?>"><i class="fa fa-circle-o"></i>Concessions &amp; Services</a></li>
                    <?php endif; ?>
                    <li><a href="<?= base_url('fee_management/refunds') ?>"><i class="fa fa-circle-o"></i>Refunds</a></li>

                    <li class="g-sub-sec">Online Payments</li>
                    <li><a href="<?= base_url('fee_management/gateway') ?>"><i class="fa fa-circle-o"></i>Payment Gateway</a></li>
                    <li><a href="<?= base_url('fee_management/online_payments') ?>"><i class="fa fa-circle-o"></i>Online Payments</a></li>
                    <li><a href="<?= base_url('fee_management/payment_reconciliation') ?>"><i class="fa fa-circle-o"></i>Reconciliation</a></li>

                    <li class="g-sub-sec">Debug</li>
                    <li><a href="<?= base_url('fees/transaction_audit') ?>"><i class="fa fa-circle-o"></i>Transaction Audit</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Account Management', $school_features) && $can('Accounting')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-calculator"></i><span>Accounting</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('accounting') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('accounting/chart') ?>"><i class="fa fa-circle-o"></i>Chart of Accounts</a></li>
                    <li><a href="<?= base_url('accounting/ledger') ?>"><i class="fa fa-circle-o"></i>Journal Entries</a></li>
                    <li><a href="<?= base_url('accounting/income-expense') ?>"><i class="fa fa-circle-o"></i>Income &amp; Expense</a></li>
                    <li><a href="<?= base_url('accounting/cash-book') ?>"><i class="fa fa-circle-o"></i>Cash Book</a></li>
                    <li><a href="<?= base_url('accounting/bank-recon') ?>"><i class="fa fa-circle-o"></i>Bank Reconciliation</a></li>
                    <li><a href="<?= base_url('accounting/reports') ?>"><i class="fa fa-circle-o"></i>Financial Reports</a></li>
                    <li><a href="<?= base_url('accounting/settings') ?>"><i class="fa fa-circle-o"></i>Settings</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════
                 CAMPUS — communication, events, gallery, operations
                 ═══════════════════════════════════════════════════════════ -->
            <?php if (isset($school_features) && (in_array('Notice and Announcement',$school_features)||in_array('School Management',$school_features)) && ($can('Communication') || $can('Events') || $can('Operations'))): ?>
            <li class="g-sec">Campus</li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('Notice and Announcement', $school_features) && $can('Communication')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-comments"></i><span>Communication</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('communication') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('communication/messages') ?>"><i class="fa fa-circle-o"></i>Messages</a></li>
                    <li><a href="<?= base_url('communication/notices') ?>"><i class="fa fa-circle-o"></i>Notice Board</a></li>
                    <li><a href="<?= base_url('communication/circulars') ?>"><i class="fa fa-circle-o"></i>Circulars</a></li>
                    <li><a href="<?= base_url('communication/templates') ?>"><i class="fa fa-circle-o"></i>Templates</a></li>
                    <li><a href="<?= base_url('communication/triggers') ?>"><i class="fa fa-circle-o"></i>Alert Triggers</a></li>
                    <li><a href="<?= base_url('communication/logs') ?>"><i class="fa fa-circle-o"></i>Delivery Logs</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php /* PERF: Message Monitor disabled (unused; read the whole RTDB message tree per load).
                     Link shows only when re-enabled via env ENABLE_MESSAGE_MONITOR=1. */ ?>
            <?php if (getenv('ENABLE_MESSAGE_MONITOR') && $can('Message Monitor')): ?>
            <li class="sidebar-single"><a href="<?= base_url('message_monitor') ?>"><i class="fa fa-eye"></i><span>Message Monitor</span></a></li>
            <?php endif; ?>

            <?php if ($can('Stories')): ?>
            <li class="sidebar-single"><a href="<?= base_url('stories') ?>"><i class="fa fa-camera-retro"></i><span>Stories</span></a></li>
            <?php endif; ?>


            <?php if (isset($school_features) && in_array('Notice and Announcement', $school_features) && $can('Events')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-calendar"></i><span>Events</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('events') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('events/list') ?>"><i class="fa fa-circle-o"></i>All Events</a></li>
                    <li><a href="<?= base_url('events/calendar') ?>"><i class="fa fa-circle-o"></i>Calendar</a></li>
                    <li><a href="<?= base_url('events/participation') ?>"><i class="fa fa-circle-o"></i>Participation</a></li>
                    <li><a href="<?= base_url('ptm') ?>"><i class="fa fa-circle-o"></i>Parent-Teacher Meetings</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if (isset($school_features) && in_array('School Management', $school_features) && $can('Events')): ?>
            <li class="sidebar-single">
                <a href="<?= base_url('schools/schoolgallery') ?>"><i class="fa fa-picture-o"></i><span>Gallery</span></a>
            </li>
            <?php endif; ?>

            <?php if ($can('Operations')): ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-cog"></i><span>Operations</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('operations') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('library') ?>"><i class="fa fa-circle-o"></i>Library</a></li>
                    <li><a href="<?= base_url('transport') ?>"><i class="fa fa-circle-o"></i>Transport</a></li>
                    <li><a href="<?= base_url('hostel') ?>"><i class="fa fa-circle-o"></i>Hostel</a></li>
                    <li><a href="<?= base_url('inventory') ?>"><i class="fa fa-circle-o"></i>Inventory</a></li>
                    <li><a href="<?= base_url('assets') ?>"><i class="fa fa-circle-o"></i>Assets</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <!-- ═══════════════════════════════════════════════════════════
                 SETTINGS & SYSTEM
                 ═══════════════════════════════════════════════════════════ -->
            <?php if ($can('Admin Users') || $can('Reports')): ?>
            <li class="g-sec">Settings</li>
            <?php endif; ?>

            <?php if ($can('Admin Users')): ?>
            <li class="sidebar-single"><a href="<?= base_url('admin_users') ?>"><i class="fa fa-user-circle-o"></i><span>Admin Users</span></a></li>
            <?php endif; ?>
            <?php if (strcasecmp($admin_role ?? '', 'School Super Admin') === 0): ?>
            <li class="sidebar-single"><a href="<?= base_url('admin_users/school_super_admins') ?>"><i class="fa fa-user-shield"></i><span>School Super Admins</span></a></li>
            <?php endif; ?>
            <?php if ($can('Admin Users')): ?>
            <li class="sidebar-single"><a href="<?= base_url('audit_logs') ?>"><i class="fa fa-shield"></i><span>Audit Logs</span></a></li>
            <li class="sidebar-single"><a href="<?= base_url('school_backup') ?>"><i class="fa fa-cloud-download"></i><span>Backup</span></a></li>
            <?php endif; ?>

            <?php if ($can('Device Management')): ?>
            <li class="sidebar-single"><a href="<?= base_url('device_management') ?>"><i class="fa fa-mobile"></i><span>Device Management</span></a></li>
            <?php endif; ?>

            <?php if ($can('Admin Users')): ?>
            <li class="sidebar-single"><a href="<?= base_url('health_check') ?>"><i class="fa fa-heartbeat"></i><span>Health Check</span></a></li>
            <?php endif; ?>

            <!-- SA Panel link removed from school admin sidebar.
                 The SA panel is a separate platform-level tool accessed via /superadmin/login.
                 School admins (even with "Super Admin" role) should not see or access it from here. -->

        </ul>
    </section>

    <div class="g-sb-foot">
        <?php
            $ini = 'AD';
            if (!empty($admin_name)) {
                $parts = explode(' ', trim($admin_name));
                $ini = strtoupper(substr($parts[0],0,1).(isset($parts[1])?substr($parts[1],0,1):''));
            }
        ?>
        <div class="g-av"><?= htmlspecialchars($ini, ENT_QUOTES, 'UTF-8') ?></div>
        <div style="flex:1;min-width:0">
            <div class="g-av-name"><?= htmlspecialchars($admin_name ?? 'Admin', ENT_QUOTES, 'UTF-8') ?></div>
            <div class="g-av-role"><?= htmlspecialchars($admin_role ?? 'Administrator', ENT_QUOTES, 'UTF-8') ?></div>
        </div>
        <a href="<?= base_url('admin_login/logout') ?>" class="g-av-out" title="Logout"><i class="fa fa-sign-out"></i></a>
    </div>
</aside>

        <!-- ═══════════════════ THEME + BELL SCRIPT ═══════════════════ -->
<script>
(function () {
    'use strict';
    var html=document.documentElement, body=document.body;
    var btn=document.getElementById('themeToggle');
    var tIcon=document.getElementById('themeIcon'), tLbl=document.getElementById('themeLabel');

    /* THEME */
    function getAutoTheme(){var h=new Date().getHours();return(h>=6&&h<18)?'day':'night';}
    var saved=localStorage.getItem('graderiq_theme')||getAutoTheme();
    function applyTheme(t){
        html.setAttribute('data-theme',t); body.setAttribute('data-theme',t);
        var dbRoot=document.getElementById('dbRoot');
        if(dbRoot) dbRoot.setAttribute('data-theme',t==='night'?'dark':'light');
        if(tIcon){tIcon.className='fa '+(t==='day'?'fa-sun-o':'fa-moon-o');tIcon.style.fontSize='11px';}
        if(tLbl)  tLbl.textContent=(t==='day')?'Day':'Night';
        localStorage.setItem('graderiq_theme',t);
    }
    applyTheme(saved);
    /* Sync dbRoot after content-wrapper is parsed (fixes dashboard flash) */
    document.addEventListener('DOMContentLoaded',function(){
        var dbRoot=document.getElementById('dbRoot');
        if(dbRoot) dbRoot.setAttribute('data-theme',html.getAttribute('data-theme')==='day'?'light':'dark');
    });
    if(btn) btn.addEventListener('click',function(){applyTheme(html.getAttribute('data-theme')==='night'?'day':'night');});

    /* ACTIVE SIDEBAR LINK */
    var curPath=window.location.pathname.replace(/\/$/,'');
    var menu=document.querySelector('.sidebar-menu');
    if(menu){
        menu.querySelectorAll('li.active').forEach(function(e){e.classList.remove('active');});
        var best=null, bestLen=0;
        menu.querySelectorAll('a[href]').forEach(function(a){
            var base=BASE_URL.replace(/\/$/,'');
            var rel=(a.getAttribute('href')||'').replace(base,'').replace(/\/$/,'')||'/';
            if(curPath===rel||curPath.indexOf(rel+'/')===0){
                if(rel.length>bestLen){bestLen=rel.length;best=a;}
            }
        });
        if(best){
            var li=best.closest('li');
            if(li){
                li.classList.add('active');
                var pUl=li.closest('.treeview-menu');
                if(pUl){var pLi=pUl.closest('.treeview');if(pLi)pLi.classList.add('active','menu-open');}
            }
        }
    }

    /* Persist sidebar collapse state on desktop. AdminLTE's push-menu toggles
       'sidebar-collapse' on <body> for width > 767; we save the result so the
       user's expand/collapse choice survives navigation (matches the SA panel). */
    var _sbToggle=document.querySelector('.sidebar-toggle[data-toggle="push-menu"]');
    /* Keep the toggle's accessible label + state in sync with reality:
       desktop = collapse/expand the rail; mobile = open/close the off-canvas menu. */
    function _syncSbToggle(){
        if(!_sbToggle) return;
        var mobile=window.innerWidth<=767, open;
        if(mobile){ open=document.body.classList.contains('sidebar-open'); }
        else      { open=!document.body.classList.contains('sidebar-collapse'); }
        var label=mobile?(open?'Close menu':'Open menu'):(open?'Collapse sidebar':'Expand sidebar');
        _sbToggle.setAttribute('aria-label',label);
        _sbToggle.setAttribute('title',label);
        _sbToggle.setAttribute('aria-expanded',open?'true':'false');
    }
    if(_sbToggle){
        _sbToggle.addEventListener('click',function(){
            setTimeout(function(){
                if(window.innerWidth>767){
                    try{localStorage.setItem('graderiq_sidebar',document.body.classList.contains('sidebar-collapse')?'collapsed':'expanded');}catch(e){}
                }
                _syncSbToggle();
            },20);
        });
        _syncSbToggle();
        window.addEventListener('resize',_syncSbToggle);
    }

    /* Mobile: tap the scrim (anywhere outside the sidebar / toggle) to close the
       off-canvas sidebar. AdminLTE opens it by adding 'sidebar-open' on <body>. */
    document.addEventListener('click',function(e){
        if(window.innerWidth>767) return;
        if(!document.body.classList.contains('sidebar-open')) return;
        if(e.target.closest('.main-sidebar')||e.target.closest('.sidebar-toggle')) return;
        document.body.classList.remove('sidebar-open');
        _syncSbToggle();
    });

    /* BELL */
    var RK='gbell_<?= md5(($school_firebase_key ?? $school_id ?? '') . ($session_year ?? '')) ?>';
    var readIds=JSON.parse(localStorage.getItem(RK)||'[]'), bData=[];
    var $bellBtn=document.getElementById('gBellBtn');
    var $panel=document.getElementById('gBellPanel');
    var $list=document.getElementById('gBellList');
    var $badge=document.getElementById('gBadge');

    if($bellBtn) $bellBtn.addEventListener('click',function(e){e.stopPropagation();$panel.classList.toggle('open');});
    document.addEventListener('click',function(e){
        var wrap=document.getElementById('gBellWrap');
        if($panel&&wrap&&!wrap.contains(e.target))$panel.classList.remove('open');
    });

    var _hasCommunication = <?= json_encode(has_permission('Communication')) ?>;
    function fetchBell(){
        if(!_hasCommunication){ if($list) $list.innerHTML='<div class="g-bell-empty">No notifications</div>'; return; }
        fetch('<?= base_url("NoticeAnnouncement/fetch_recent_notices") ?>',{cache:'no-store'})
            .then(function(r){return r.json();})
            .then(function(d){bData=Array.isArray(d)?d:[];renderBell();updateBadge();})
            .catch(function(){if($list)$list.innerHTML='<div class="g-bell-empty"><i class="fa fa-exclamation-circle"></i> Could not load</div>';});
    }

    function renderBell(){
        if(!$list) return;
        if(!bData.length){$list.innerHTML='<div class="g-bell-empty"><i class="fa fa-bell-slash-o"></i> No notices yet</div>';return;}
        var h='';
        bData.forEach(function(n){
            var isNew=readIds.indexOf(n.id)===-1;
            var ts=n.Time_Stamp||n.Timestamp||0;
            var ago=timeAgo(ts?new Date(ts):new Date());
            var desc=(n.Description||'').substring(0,60);
            h+='<a class="g-bell-item'+(isNew?' unread':'')+'" href="<?= base_url("communication/notices") ?>" data-id="'+esc(n.id)+'">'
              +'<span class="g-bld '+(isNew?'new':'old')+'"></span>'
              +'<div style="flex:1;min-width:0">'
              +'<div class="g-bell-nt">'+esc(n.Title||'Untitled')+'</div>'
              +'<div class="g-bell-nd">'+esc(desc)+'</div>'
              +'<div class="g-bell-nt2"><i class="fa fa-clock-o" style="margin-right:3px"></i>'+ago+'</div>'
              +'</div></a>';
        });
        $list.innerHTML=h;
        $list.querySelectorAll('a[data-id]').forEach(function(a){
            a.addEventListener('click',function(){
                var id=a.getAttribute('data-id');
                if(id&&readIds.indexOf(id)===-1){readIds.push(id);localStorage.setItem(RK,JSON.stringify(readIds));updateBadge();}
            });
        });
    }

    function updateBadge(){
        var u=bData.filter(function(n){return readIds.indexOf(n.id)===-1;}).length;
        if($badge){$badge.textContent=u>9?'9+':String(u);$badge.setAttribute('data-n',u);$badge.style.display=u?'flex':'none';}
    }

    window.gMarkAllRead=function(){
        bData.forEach(function(n){if(readIds.indexOf(n.id)===-1)readIds.push(n.id);});
        localStorage.setItem(RK,JSON.stringify(readIds));updateBadge();renderBell();
        if($panel)$panel.classList.remove('open');
    };

    function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
    function timeAgo(d){
        var diff=Math.floor((Date.now()-d.getTime())/1000);
        if(diff<60) return 'Just now';
        if(diff<3600) return Math.floor(diff/60)+'m ago';
        if(diff<86400) return Math.floor(diff/3600)+'h ago';
        return d.toLocaleDateString('en-IN',{day:'numeric',month:'short'});
    }

    /* ── Workflow tasks in bell panel ── */
    var tData=[];
    var $tList=document.getElementById('gTaskList');
    var $tBadge=document.getElementById('gTaskBadge');

    // Shared tasks fetch — the dashboard panel (home.php) reuses the SAME
    // promise instead of refiring the endpoint, eliminating a duplicate
    // 10-second call on every dashboard load.
    window.__graderTasksPromise = window.__graderTasksPromise || fetch(
        '<?= base_url("notifications/get_tasks") ?>',
        {cache:'no-store'}
    ).then(function(r){return r.json();});

    function fetchTasks(){
        window.__graderTasksPromise
            .then(function(d){
                if(!d || d.status!=='success')return;
                tData=(d.tasks||[]).concat((d.alerts||[]).map(function(a){
                    return{id:a.key,icon:a.icon,color:a.type==='error'?'#dc2626':'#d97706',title:a.title,detail:a.detail,action:a.action,priority:'high',module:a.module};
                }));
                renderTasks();
                updateTaskBadge();
            })
            .catch(function(){});
    }

    function renderTasks(){
        if(!$tList)return;
        if(!tData.length){$tList.innerHTML='<div class="g-bell-empty"><i class="fa fa-check-circle" style="color:var(--gold)"></i> All caught up!</div>';return;}
        var base='<?= base_url() ?>';
        var h='';
        tData.slice(0,8).forEach(function(t){
            var href=t.action?(base+t.action):'#';
            h+='<a class="g-task-item" href="'+esc(href)+'">'
              +'<div class="g-task-icon" style="background:'+(t.color||'#0f766e')+'"><i class="fa '+(t.icon||'fa-tasks')+'"></i></div>'
              +'<div style="flex:1;min-width:0">'
              +'<div class="g-task-title">'+esc(t.title)+'</div>'
              +'<div class="g-task-detail">'+esc(t.detail||'')+'</div>'
              +'</div>'
              +'<span class="g-task-pri '+(t.priority||'low')+'">'+(t.priority||'low').toUpperCase()+'</span>'
              +'</a>';
        });
        $tList.innerHTML=h;
    }

    function updateTaskBadge(){
        var c=tData.filter(function(t){return t.priority==='high';}).length;
        if($tBadge){$tBadge.textContent=String(c);$tBadge.style.display=c?'inline-block':'none';}
        // Also add task count to bell badge total
        updateBadge();
    }

    // Override updateBadge to merge notice + task counts
    var _origUpdateBadge = updateBadge;
    updateBadge = function(){
        var noticeUnread=bData.filter(function(n){return readIds.indexOf(n.id)===-1;}).length;
        var taskHigh=tData.filter(function(t){return t.priority==='high';}).length;
        var total=noticeUnread+taskHigh;
        if($badge){$badge.textContent=total>9?'9+':String(total);$badge.setAttribute('data-n',total);$badge.style.display=total?'flex':'none';}
    };

    window.gSwitchBellTab=function(tab,btn){
        var tabs=document.querySelectorAll('.g-bell-tab');
        tabs.forEach(function(t){t.classList.remove('active');});
        if(btn)btn.classList.add('active');
        var showNotices=(tab==='notices');
        if($list)$list.style.display=showNotices?'':'none';
        if($tList)$tList.style.display=showNotices?'none':'';
        var ftN=document.getElementById('gBellFtNotices');
        var ftT=document.getElementById('gBellFtTasks');
        if(ftN)ftN.style.display=showNotices?'':'none';
        if(ftT)ftT.style.display=showNotices?'none':'';
    };

    // 2026-04-24: throttled the bell / tasks pollers.
    //   - 90 s was firing ~40 times / hour / page on EVERY admin page;
    //     each call cost ~3-5 s of Firestore work. Users observed the
    //     background traffic and asked if it was necessary.
    //   - Bumped to 5 min / 6 min so it still feels live for normal
    //     desk use, without hammering the backend.
    //   - skipWhenHidden: Page Visibility API — when the user tabs
    //     away or minimises the window, we stop polling entirely.
    //     Saves another ~80 % of background calls in practice.
    //   - Refresh is also re-fired on tab-visible so a returning user
    //     sees an up-to-date count immediately (no stale cache).
    fetchBell();
    fetchTasks();
    var _bellTimer  = setInterval(function(){ if(!document.hidden) fetchBell();  }, 300000); // 5 min
    var _tasksTimer = setInterval(function(){ if(!document.hidden) fetchTasks(); }, 360000); // 6 min
    document.addEventListener('visibilitychange', function(){
        if (!document.hidden) { fetchBell(); fetchTasks(); }
    });
})();
</script>

<script>
/* ── Session Switcher ────────────────────────────────────────────────────── */
(function () {
    var btn   = document.getElementById('gSessBtn');
    var panel = document.getElementById('gSessPanel');
    if (!btn || !panel) return;

    // Toggle dropdown
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        panel.classList.toggle('open');
    });
    document.addEventListener('click', function () { panel.classList.remove('open'); });

    // Switch session on item click
    document.querySelectorAll('.g-sess-item').forEach(function (item) {
        item.addEventListener('click', function () {
            var year = this.dataset.year;
            if (this.classList.contains('g-sess-item--active')) {
                panel.classList.remove('open');
                return;
            }
            // SC-Step6 (2026-06-02): repointed from admin/switch_session
            // (retired) to canonical school_config/set_active_session.
            // Body param renamed session_year → session per School_config
            // endpoint contract. set_active_session was widened from
            // ADMIN_ROLES to VIEW_ROLES in the same commit to preserve
            // the header dropdown's switching capability for all 14 roles.
            $.post(BASE_URL + 'school_config/set_active_session', { session: year })
             .done(function (res) {
                 if (res && res.status === 'success') { window.location.reload(); }
             })
             .fail(function () { alert('Failed to switch session. Please try again.'); });
        });
    });

    // New session button → open modal with auto-suggested next year
    var newBtn = document.getElementById('gSessNewBtn');
    if (newBtn) {
        newBtn.addEventListener('click', function () {
            panel.classList.remove('open');
            var items = document.querySelectorAll('.g-sess-item');
            var latest = '';
            items.forEach(function (i) { if (i.dataset.year > latest) latest = i.dataset.year; });
            var suggestion = '';
            if (latest) {
                var base = parseInt(latest.split('-')[0]) + 1;
                suggestion = base + '-' + String(base + 1).slice(-2);
            }
            document.getElementById('gNewSessInput').value = suggestion;
            document.getElementById('gNewSessHint').textContent = suggestion ? 'Suggested: ' + suggestion : '';
            document.getElementById('gCreateSessError').style.display = 'none';
            $('#gCreateSessModal').modal('show');
        });
    }

    // Create session submit
    var createBtn = document.getElementById('gCreateSessSubmit');
    if (createBtn) {
        createBtn.addEventListener('click', function () {
            var year   = document.getElementById('gNewSessInput').value.trim();
            var errBox = document.getElementById('gCreateSessError');
            errBox.style.display = 'none';

            if (!/^\d{4}-\d{2}$/.test(year)) {
                errBox.textContent = 'Format must be YYYY-YY (e.g. 2026-27)';
                errBox.style.display = 'block';
                return;
            }
            createBtn.disabled = true;
            createBtn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Creating\u2026';

            // SC-Step6 (2026-06-02): repointed from admin/create_session
            // (retired) to canonical school_config/add_session +
            // school_config/set_active_session. Preserves the original
            // "Create & Switch" UX via 2 sequential POSTs (D2=a operator
            // decision). Body param renamed session_year → session per
            // School_config endpoint contract. UI button visibility is
            // separately SA-only-gated upstream (D3=b — header markup
            // shows gSessNewBtn only to Super Admin role).
            $.post(BASE_URL + 'school_config/add_session', { session: year })
             .done(function (res) {
                 if (res && res.status === 'success') {
                     $('#gCreateSessModal').modal('hide');
                     // Switch to the newly created session then reload
                     $.post(BASE_URL + 'school_config/set_active_session', { session: year })
                      .always(function () { window.location.reload(); });
                 } else {
                     errBox.textContent = (res && res.message) || 'Failed to create session.';
                     errBox.style.display = 'block';
                     createBtn.disabled = false;
                     createBtn.innerHTML = '<i class="fa fa-plus"></i> Create &amp; Switch';
                 }
             })
             .fail(function (xhr) {
                 var msg = 'Server error. Please try again.';
                 try { msg = JSON.parse(xhr.responseText).message || msg; } catch (e) {}
                 errBox.textContent = msg;
                 errBox.style.display = 'block';
                 createBtn.disabled = false;
                 createBtn.innerHTML = '<i class="fa fa-plus"></i> Create &amp; Switch';
             });
        });
    }
})();
</script>