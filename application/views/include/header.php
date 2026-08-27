<?php defined('BASEPATH') OR exit('No direct script access allowed'); ?>
<!DOCTYPE html>
<html>
<head>
    <!-- Kill the black flash between pages: declare a light color-scheme so the
         browser never paints a DARK canvas during navigation (the macOS dark-mode
         cause), and set the theme + an explicit <html> background before any CSS
         paints. Night-theme users get color-scheme:dark set via the script below. -->
    <meta name="color-scheme" content="light">
    <script>(function(){try{var t=localStorage.getItem('graderiq_theme');if(!t){var h=new Date().getHours();t=(h>=6&&h<18)?'day':'night';}var d=document.documentElement,dark=(t==='night');d.setAttribute('data-theme',t);d.style.backgroundColor='#F7F4F1';/* light base kills inter-page black flash; chrome stays dark via CSS --bg2/3, dashboard via .db-root */}catch(e){}})();</script>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>ZenXii Admin</title>
    <!-- Favicon / tab icon — canonical ZenXii mark -->
    <link rel="icon" type="image/png" href="<?= base_url('Designs/favicon.png?v=2') ?>">
    <link rel="apple-touch-icon" href="<?= base_url('Designs/favicon.png?v=2') ?>">
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
            var LOGIN_URL = '<?= base_url("admin_login") ?>';
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
                // Session-expiry guard for fetch() (the jQuery ajaxComplete handler
                // in the footer never fires for fetch, and every attendance page
                // uses fetch). On a 401/403 whose body reads as an auth failure,
                // break OUT of any embed iframe to the login page. (FE-C1)
                return _fetch.call(this, input, init).then(function (res) {
                    if (res && (res.status === 401 || res.status === 403)) {
                        res.clone().text().then(function (txt) {
                            var msg = '';
                            try { msg = (JSON.parse(txt || '{}').message) || ''; } catch (e) {}
                            if (/session|expired|log\s*in|deactivat|subscription|unauthor/i.test(msg)) {
                                try { (window.top || window).location.href = LOGIN_URL; }
                                catch (e) { window.location.href = LOGIN_URL; }
                            }
                        }).catch(function () {});
                    }
                    return res;
                });
            };
        }());
    </script>
<?php
    /* Embed mode — when a page is loaded with ?embed=1 (used by the attendance
       tab-shell pages that host each sub-page in an isolated <iframe>), emit the
       full <head> assets + scripts but hide the topbar / sidebar / footer chrome
       and zero the AdminLTE content offset. The hosted page keeps ALL its own JS
       and element IDs — no DOM merge, no collisions. Read straight off the query
       string so NO controller method has to change. */
    $att_embed = ($this->input->get('embed') === '1');
?>
<?php if ($att_embed): ?>
    <style id="att-embed-css">
        html, body { background: transparent !important; }
        .main-header, .main-sidebar, .main-footer, .control-sidebar, .control-sidebar-bg,
        #zxLoaderOverlay { display: none !important; }
        /* No sidebar offset AND no topbar offset — the panel reserves a top margin
           for the fixed topbar; with the topbar hidden that margin would expose the
           dark body strip behind it (the "dark bar"). Zero both. */
        .content-wrapper { margin-left: 0 !important; margin-top: 0 !important; box-shadow: none !important; }
        /* AdminLTE's layout JS pins .content-wrapper min-height to the WINDOW height
           on every resize. Inside the shell's auto-sizing iframe the window IS the
           iframe, so that inline height feeds the fitFrame->ResizeObserver loop and
           the page grows without bound ("scroll keeps expanding"). Zero it out — the
           shell sizes the iframe to real content, so no viewport-height is needed. */
        html, body { height: auto !important; min-height: 0 !important; }
        .content-wrapper, .content { min-height: 0 !important; height: auto !important; }
        body.att-embed { overflow-x: hidden !important; background: transparent !important; }
        /* Breadcrumb is redundant inside a shell tab — the tab already names the page. */
        body.att-embed .att-breadcrumb { display: none !important; }
        body.att-embed .content > .container-fluid,
        body.att-embed .content { padding-top: 14px !important; }
        /* Inside a tab-shell iframe the page's own view-switcher, "Back" link
           and — importantly — its OWN header duplicate the shell chrome above
           it. Hide them (covers attendance sub-pages AND admission CRM views).
           The shell's own header (.att-shell-head / .ac-shell-head) is a
           different class and stays. */
        body.att-embed .ac-viewsw, body.att-embed .ac-back,
        body.att-embed .ac-head, body.att-embed .aa-hdr, body.att-embed .leads-hdr,
        body.att-embed .att-header, body.att-embed .sl-page-head,
        body.att-embed .sr-hdr, body.att-embed .au-page-hdr, body.att-embed .pl-page-hdr {
            display: none !important; }
        /* Don't force full-viewport height inside an embedded page, and trim the
           big top/bottom padding — the shell auto-sizes the iframe to real
           content so the PARENT scrolls once with no stray blank space. */
        body.att-embed .ac-wrap, body.att-embed .aa-wrap, body.att-embed .leads-wrap {
            min-height: 0 !important; padding-top: 6px !important; padding-bottom: 14px !important; }
        /* Neutralize inner viewport-locked scroll containers (e.g. the attendance
           register .sa-grid-wrap / .att-grid-wrap) so content flows and the
           PARENT page scrolls once — no inner scroll, no blank space. */
        body.att-embed [class*="grid-wrap"] { max-height: none !important; overflow-x: auto !important; overflow-y: visible !important; }
    </style>
    <script>
    /* Embedded page → notify the parent shell when a fixed-position modal opens,
       so the shell can pin its iframe to the viewport. (A position:fixed modal
       inside an auto-height iframe would otherwise center in the middle of the
       tall iframe, i.e. off-screen.) Covers admission (.ac-overlay/#credModal/
       #acBusyOverlay), the Funnel detail modal (.lead-modal-bg) and
       attendance (.att-modal-overlay). */
    (function(){
        if (window.parent === window) return;
        function anyOpen(){
            var els = document.querySelectorAll('.ac-overlay, .att-modal-overlay, .lead-modal-bg, #credModal, #acBusyOverlay');
            for (var i=0;i<els.length;i++){ try { if (getComputedStyle(els[i]).display !== 'none') return true; } catch(e){} }
            return false;
        }
        var last = null;
        function report(){ var v = anyOpen(); if (v !== last){ last = v; try { window.parent.postMessage({shellModal:v}, '*'); } catch(e){} } }
        try { new MutationObserver(report).observe(document.documentElement, {subtree:true, childList:true, attributes:true, attributeFilter:['class','style']}); } catch(e){}
        window.addEventListener('load', report);
    })();

    /* Embedded CRM page → forward page-level toasts (#pageAlert) to the shell so
       they render in the real viewport, not off-screen at the top of the tall
       auto-height iframe (the reported "delayed / missed toast"). Suppresses the
       in-iframe copy while embedded. */
    (function(){
        var el = document.getElementById('pageAlert');
        if (!el) return;
        var lastMsg = '';
        function fwd(){
            if (getComputedStyle(el).display === 'none') { lastMsg = ''; return; }
            var msg = (el.textContent || '').trim();
            if (!msg || msg === lastMsg) return;
            lastMsg = msg;
            var type = /ac-alert-error/.test(el.className) ? 'error' : (/ac-alert-success/.test(el.className) ? 'success' : 'info');
            try { window.parent.postMessage({shellToast:{msg:msg, type:type}}, '*'); } catch(e){}
            el.style.display = 'none';   // don't also show the off-screen in-iframe copy
        }
        try { new MutationObserver(fwd).observe(el, {attributes:true, childList:true, characterData:true, subtree:true, attributeFilter:['style','class']}); } catch(e){}
    })();
    </script>
<?php endif; ?>
    <script>
    /* Shared modal accessibility for the Admission-CRM / dialog overlays:
       focus management (move focus in, trap Tab, restore on close), Escape to
       close, and role/aria-modal. Self-gating: only initializes when a matching
       overlay exists in the DOM (i.e. a CRM/attendance page), so it is an inert
       no-op on every other admin page. Complements the modal-pin observer. */
    (function(){
        var SEL = '.ac-overlay, .lead-modal-bg, .att-modal-overlay';
        if (!document.querySelector(SEL + ', #credModal')) return;   // not a modal page → skip
        var FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';
        var active = null, lastFocus = null;
        function vis(m){ try { return getComputedStyle(m).display !== 'none' && m.offsetWidth + m.offsetHeight > 0; } catch(e){ return false; } }
        function foc(m){ return Array.prototype.filter.call(m.querySelectorAll(FOCUSABLE), function(el){ return el.offsetWidth + el.offsetHeight > 0; }); }
        function openModal(m){
            if (active === m) return;
            active = m; lastFocus = document.activeElement;
            if (!m.getAttribute('role')) m.setAttribute('role', 'dialog');
            m.setAttribute('aria-modal', 'true');
            var f = foc(m); if (f.length) setTimeout(function(){ try { f[0].focus(); } catch(e){} }, 40);
        }
        function closeModal(){
            var prev = lastFocus; active = null; lastFocus = null;
            if (prev && prev.focus) { try { prev.focus(); } catch(e){} }
        }
        function tryClose(m){
            /* Prefer the view's own close control (keeps its JS state in sync);
               fall back to removing the .active class the CRM overlays use. */
            var btn = m.querySelector('[data-modal-close],[onclick*="close"],[onclick*="Close"],.ac-x,.modal-close,.lead-modal-x');
            if (btn) { btn.click(); return; }
            m.classList.remove('active');
        }
        document.addEventListener('keydown', function(e){
            if (!active) return;
            if (e.key === 'Escape' || e.keyCode === 27) { e.preventDefault(); tryClose(active); return; }
            if (e.key === 'Tab' || e.keyCode === 9) {
                var f = foc(active); if (!f.length) { e.preventDefault(); return; }
                var first = f[0], last = f[f.length - 1];
                if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
                else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
            }
        }, true);
        function scan(){
            var open = null, all = document.querySelectorAll(SEL + ', #credModal');
            for (var i = 0; i < all.length; i++) { if (vis(all[i])) { open = all[i]; break; } }
            if (open && open !== active) openModal(open);
            else if (!open && active) closeModal();
        }
        try { new MutationObserver(scan).observe(document.documentElement, {subtree:true, childList:true, attributes:true, attributeFilter:['class','style']}); } catch(e){}
        if (document.readyState !== 'loading') scan(); else document.addEventListener('DOMContentLoaded', scan);
    })();
    </script>
</head>


<body class="hold-transition skin-blue sidebar-mini<?= $att_embed ? ' att-embed' : '' ?>">
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
<?php if (!$att_embed): ?>
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

        <div class="g-search" id="gSearchBox">
            <i class="fa fa-search"></i>
            <input type="text" id="globalSearch" placeholder="Search students, staff, pages…"
                   autocomplete="off" role="combobox" aria-autocomplete="list"
                   aria-expanded="false" aria-controls="gSearchPanel" spellcheck="false">
            <kbd class="g-search-kbd" id="gSearchKbd" aria-hidden="true">⌘K</kbd>
            <div class="g-search-panel" id="gSearchPanel" role="listbox" aria-label="Search results"></div>
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
<?php else: ?>
<!-- Embed mode (attendance tab-shell iframes): topbar suppressed. Previously this
     full navbar (logo, global search, bell + its pollers, session switcher, user
     menu) was built then hidden with CSS in every iframe. Empty shell kept so
     AdminLTE Layout/PushMenu selectors resolve to a no-op instead of nothing. -->
<header class="main-header" aria-hidden="true"></header>
<?php endif; ?>

<!-- ── Create Session Modal — at body level so Bootstrap stacking works ── -->
<?php if (!$att_embed && in_array(($admin_role ?? ''), ['Super Admin', 'School Super Admin'])): ?>
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
<?php /* Embed mode: skip the fixed-position banner — the host attendance shell page
         (non-embed) already renders it; inside an iframe it would double up and its
         position:fixed anchors to the iframe, not the viewport. */ ?>
<?php if (!$att_embed && !empty($subscription_warning)): ?>
<div id="subWarnBanner" style="
    position:fixed;top:var(--hh);left:0;right:0;z-index:1039;
    background:linear-gradient(90deg,#3A1811,#5A2A1C,#3A1811);
    border-bottom:1px solid rgba(188,90,60,.4);
    padding:8px 20px;display:flex;align-items:center;gap:10px;
    font-family:var(--font-b);font-size:12.5px;color:#F3E4DC;
    box-shadow:0 2px 12px rgba(0,0,0,.35);">
    <i class="fa fa-exclamation-triangle" style="color:#D4725C;font-size:14px;flex-shrink:0;"></i>
    <span style="flex:1;"><?= htmlspecialchars($subscription_warning, ENT_QUOTES, 'UTF-8') ?></span>
    <a href="<?= base_url('admin_login/logout') ?>"
       style="background:rgba(188,90,60,.25);border:1px solid rgba(188,90,60,.5);color:#D4725C;
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
    /* Locked sidebar item — visible but not clickable (no access) */
    .sidebar-menu .treeview-menu li.nav-locked > a{
        cursor:not-allowed; opacity:.45; pointer-events:none;
    }
    .sidebar-menu .treeview-menu li.nav-locked > a .fa-lock{ font-size:11px; }
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
<?php if (!$att_embed): ?>
<aside class="main-sidebar">
    <section class="sidebar">
        <?php
        // ── RBAC sidebar helper ──────────────────────────────────────
        // $rbac_permissions is shared by MY_Controller and already reflects the
        // caller's fully-resolved effective access (role union + overrides, with
        // the ROLE_ADMIN backfill / fold safety net applied). The menu therefore
        // MIRRORS enforcement: gate on the shared map, not on a hardcoded role
        // name. Only the two true god roles short-circuit (matching
        // RBAC_BYPASS_ROLES) — 'Admin' was dropped here in lockstep with its
        // removal from the bypass list, so a restricted admin no longer sees menu
        // items the controller would 403 (and an all-modules admin still sees
        // everything, because $rp already holds every module for them).
        $rp = isset($rbac_permissions) && is_array($rbac_permissions) ? $rbac_permissions : [];
        $can = function(string $module) use ($rp, $admin_role) {
            // Bypass god roles always see everything (mirrors RBAC_BYPASS_ROLES).
            if (isset($admin_role) && in_array($admin_role, ['Super Admin', 'School Super Admin'], true)) return true;
            if (in_array($module, $rp, true)) return true;
            // Parent/child inheritance (mirrors has_permission): a child is
            // granted by its umbrella parent; an umbrella group shows if the role
            // holds any of its children. Keeps legacy 'Operations' roles intact.
            $tree = defined('RBAC_MODULE_CHILDREN') ? RBAC_MODULE_CHILDREN : [];
            foreach ($tree as $parent => $children) {
                if (in_array($module, $children, true) && in_array($parent, $rp, true)) return true;
            }
            if (isset($tree[$module])) {
                foreach ($tree[$module] as $child) {
                    if (in_array($child, $rp, true)) return true;
                }
            }
            return false;
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
            <?php /* Supersession (Tier 3): the "Departments & Roles" (Org) sidebar door
               was COLLAPSED into "Staff & Roles" (Staff_access Tab 1) — the 3-tab
               superset that edits the SAME schools.staffRoles/departments. Org routes
               stay live (deep links + backfill_legacy_departments), only the duplicate
               menu entry is removed. Re-add the <li> to base_url('org') to restore. */ ?>
            <?php if ($can('Admin Users')): ?>
            <li class="sidebar-single">
                <a href="<?= base_url('staff_access') ?>"><i class="fa fa-shield"></i><span>Staff &amp; Roles</span></a>
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
                <a href="#"><i class="fa fa-id-card-o"></i><span>HR &amp; Payroll</span><span class="pull-right-container"><small class="label pull-right bg-red approval-badge" id="badgeHr" style="display:none">0</small><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('hr') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('hr/departments') ?>"><i class="fa fa-circle-o"></i>Departments</a></li>
                    <li><a href="<?= base_url('hr/recruitment') ?>"><i class="fa fa-circle-o"></i>Recruitment</a></li>
                    <li><a href="<?= base_url('ats') ?>"><i class="fa fa-circle-o"></i>Applicant Tracking</a></li>
                    <li><a href="<?= base_url('hr/leaves') ?>"><i class="fa fa-circle-o"></i>Leave Management<small class="label pull-right bg-red" id="badgeLeaveMgmt" style="display:none">0</small></a></li>
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
                <!-- Admission CRM — 6 funnel-ordered sections (IA reorg 2026-07).
                     Board (Pipeline) & Funnel (Leads) are now VIEWS inside
                     Applications, reachable via the in-page view switcher, so
                     they no longer need their own sidebar rows. -->
                <ul class="treeview-menu">
                    <li><a href="<?= base_url('sis/crm') ?>"><i class="fa fa-circle-o"></i>Overview</a></li>
                    <li><a href="<?= base_url('sis/inquiries') ?>"><i class="fa fa-circle-o"></i>Inquiries</a></li>
                    <li><a href="<?= base_url('sis/applications') ?>"><i class="fa fa-circle-o"></i>Applications</a></li>
                    <li><a href="<?= base_url('sis/waitlist') ?>"><i class="fa fa-circle-o"></i>Waiting List</a></li>
                    <li><a href="<?= base_url('sis/admission_analytics') ?>"><i class="fa fa-circle-o"></i>Analytics</a></li>
                    <li><a href="<?= base_url('sis/crm_settings') ?>"><i class="fa fa-circle-o"></i>Settings</a></li>
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
                    <span class="pull-right-container"><small class="label pull-right bg-red approval-badge" id="badgeAttendance" style="display:none">0</small><i class="fa fa-angle-left pull-right"></i></span>
                </a>
                <ul class="treeview-menu">
                    <!-- Phase 2 IA: 6 job-based pages. Mark / Approvals / Reports
                         are tab-shells hosting the former sub-pages as tabs, so the
                         flat 11-item list collapses to these six. -->
                    <li><a href="<?= base_url('attendance') ?>"><i class="fa fa-circle-o"></i>Dashboard</a></li>
                    <li><a href="<?= base_url('attendance/mark') ?>"><i class="fa fa-circle-o"></i>Mark Attendance</a></li>
                    <li><a href="<?= base_url('attendance/approvals') ?>"><i class="fa fa-circle-o"></i>Approvals<small class="label pull-right bg-red" id="badgeApprovals" style="display:none">0</small></a></li>
                    <li><a href="<?= base_url('attendance/control') ?>"><i class="fa fa-circle-o"></i>Control &amp; Locks</a></li>
                    <li><a href="<?= base_url('attendance/reports') ?>"><i class="fa fa-circle-o"></i>Reports &amp; Logs</a></li>
                    <li><a href="<?= base_url('attendance/settings') ?>"><i class="fa fa-circle-o"></i>Settings</a></li>
                </ul>
            </li>
            <?php endif; ?>

            <?php if ($can('Red Flags')): ?>
            <li class="sidebar-single"><a href="<?= base_url('red_flags') ?>"><i class="fa fa-flag"></i><span>Red Flags</span></a></li>
            <?php endif; ?>

            <?php /* Support Desk. TWO entries, deliberately gated differently.
                     "Support" is the queue and needs the module.
                     "My Tickets" is UNGATED: any of a school's staff can be
                     assigned a ticket, so hiding this behind the module is
                     exactly what made an assignee unable to see their own work.
                     See Support::mine() and rbac_helper RBAC_MODULE_META. */ ?>
            <?php if ($can('Support')): ?>
            <li class="sidebar-single"><a href="<?= base_url('support') ?>"><i class="fa fa-life-ring"></i><span>Support</span></a></li>
            <?php endif; ?>
            <li class="sidebar-single"><a href="<?= base_url('support/mine') ?>"><i class="fa fa-inbox"></i><span>My Tickets</span></a></li>

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
            <?php
                // Operations sub-items render for everyone in the group, but each
                // link is only clickable when the role holds that sub-permission
                // (or the 'Operations' umbrella, which grants all). Items the user
                // lacks show greyed + locked — visible, not clickable. This is UX
                // only; the sub-controllers still enforce access server-side.
                $opsItems = [
                    ['Dashboard', 'operations', 'Operations'],
                    ['Library',   'library',    'Library'],
                    ['Transport', 'transport',  'Transport'],
                    ['Hostel',    'hostel',     'Hostel'],
                    ['Inventory', 'inventory',  'Inventory'],
                    ['Assets',    'assets',     'Assets'],
                ];
            ?>
            <li class="treeview">
                <a href="#"><i class="fa fa-cog"></i><span>Operations</span><span class="pull-right-container"><i class="fa fa-angle-left pull-right"></i></span></a>
                <ul class="treeview-menu">
                    <?php foreach ($opsItems as $it): [$label, $route, $perm] = $it; if ($can($perm)): ?>
                    <li><a href="<?= base_url($route) ?>"><i class="fa fa-circle-o"></i><?= $label ?></a></li>
                    <?php else: ?>
                    <li class="nav-locked"><a href="#" onclick="return false;" title="You don't have access to <?= $label ?>"><i class="fa fa-lock"></i><?= $label ?></a></li>
                    <?php endif; endforeach; ?>
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
<?php else: ?>
<!-- Embed mode: sidebar suppressed. Skips the entire RBAC menu tree render (the
     $can() permission check per <li>, all base_url() calls) plus its DOM — the
     largest per-iframe cost. Empty shell kept so AdminLTE Tree/Layout selectors
     ('.main-sidebar', '[data-widget="tree"]') no-op safely; CSS already hid it. -->
<aside class="main-sidebar" aria-hidden="true"><section class="sidebar"></section></aside>
<?php endif; ?>

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

    /* PRESERVE SIDEBAR SCROLL across navigation. Each module is a full page
       reload, so the .sidebar scroll container re-renders at the top and the
       user loses their place. Restore the last position (per-tab) synchronously
       here — this IIFE runs right after the sidebar markup, so there's no flash —
       and keep it in sync as the user scrolls. */
    var _sb=document.querySelector('.main-sidebar .sidebar');
    if(_sb){
        var _SBK='graderiq_sb_scroll';
        try{var _y=parseInt(sessionStorage.getItem(_SBK),10);if(_y>0)_sb.scrollTop=_y;}catch(e){}
        /* Nothing saved (first visit / new tab): make sure the active item is on
           screen rather than defaulting to the very top. */
        if(!_sb.scrollTop&&best){
            var _r=best.getBoundingClientRect(),_sr=_sb.getBoundingClientRect();
            if(_r.top<_sr.top||_r.bottom>_sr.bottom){try{best.scrollIntoView({block:'center'});}catch(e){best.scrollIntoView();}}
        }
        var _sbT=null;
        _sb.addEventListener('scroll',function(){
            if(_sbT)return;
            _sbT=requestAnimationFrame(function(){_sbT=null;try{sessionStorage.setItem(_SBK,String(_sb.scrollTop));}catch(e){}});
        },{passive:true});
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
    // Embed mode (attendance tab-shell iframes): do NOT fire the tasks endpoint —
    // the bell/topbar that consumes it is suppressed. Resolve to an empty object so
    // any caller (e.g. home.php dashboard reuse) stays safe. Function defs unchanged.
    window.__graderTasksPromise = window.__graderTasksPromise || (
        <?= $att_embed ? 'true' : 'false' ?>
            ? Promise.resolve({})
            : fetch(
                '<?= base_url("notifications/get_tasks") ?>',
                {cache:'no-store'}
              ).then(function(r){return r.json();})
    );

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
              +'<div class="g-task-icon" style="background:'+(t.color||'#BC5A3C')+'"><i class="fa '+(t.icon||'fa-tasks')+'"></i></div>'
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
    // Embed mode: skip the bell/tasks network kickoffs AND the pollers entirely —
    // the topbar bell UI they populate is suppressed in iframes. All function defs
    // above are left intact; we only avoid firing the fetches/intervals here.
    if (!<?= $att_embed ? 'true' : 'false' ?>) {
        fetchBell();
        fetchTasks();
        var _bellTimer  = setInterval(function(){ if(!document.hidden) fetchBell();  }, 300000); // 5 min
        var _tasksTimer = setInterval(function(){ if(!document.hidden) fetchTasks(); }, 360000); // 6 min
        document.addEventListener('visibilitychange', function(){
            if (!document.hidden) { fetchBell(); fetchTasks(); }
        });
    }
})();
</script>

<!-- Live pending-approval badges (Attendance ▸ Approvals, HR ▸ Leave Management).
     When a module treeview is COLLAPSED the parent badge shows the count; when
     it's OPEN the parent badge hides and the count "moves" to the sub-item. -->
<style>
.sidebar-menu .treeview.menu-open > a .approval-badge { display: none !important; }
</style>
<script>
(function(){
    // Embed mode: the sidebar holding the badge targets is suppressed, so this
    // poller would no-op via the check below anyway — return first to guarantee
    // zero network per iframe.
    if (<?= $att_embed ? 'true' : 'false' ?>) return;
    var URL = '<?= base_url("attendance/approval_badge_counts") ?>';
    // Only poll when the user can actually see these menus (badge targets exist).
    if (!document.getElementById('badgeAttendance') && !document.getElementById('badgeHr')) return;

    function setBadge(id, n){
        var el = document.getElementById(id);
        if (!el) return;
        if (n > 0){ el.textContent = (n > 99 ? '99+' : String(n)); el.style.display = ''; }
        else      { el.textContent = '0'; el.style.display = 'none'; }
    }
    function poll(){
        fetch(URL, { cache: 'no-store', credentials: 'same-origin' })
            .then(function(r){ return r.ok ? r.json() : null; })
            .then(function(j){
                if (!j || j.status !== 'success') return;
                var a = j.attendance || {}, l = j.leave || {};
                setBadge('badgeAttendance', a.total || 0);
                setBadge('badgeApprovals',  a.total || 0);
                setBadge('badgeHr',         l.total || 0);
                setBadge('badgeLeaveMgmt',  l.total || 0);
            })
            .catch(function(){});
    }
    poll();
    setInterval(function(){ if (!document.hidden) poll(); }, 30000);
    document.addEventListener('visibilitychange', function(){ if (!document.hidden) poll(); });
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

<!-- ═══════════════════ GLOBAL SEARCH ═══════════════════ -->
<script>
(function () {
    'use strict';
    var box   = document.getElementById('gSearchBox');
    var input = document.getElementById('globalSearch');
    var panel = document.getElementById('gSearchPanel');
    var kbd   = document.getElementById('gSearchKbd');
    if (!box || !input || !panel) return;

    var isMac = /Mac|iPod|iPhone|iPad/.test(navigator.platform);
    if (kbd) kbd.textContent = isMac ? '⌘K' : 'Ctrl K';

    /* Page index — built from the (already RBAC-filtered) sidebar so it always
       matches exactly what this admin can actually open, with zero hardcoding. */
    var pages = [];
    (function () {
        var seen = {};
        document.querySelectorAll('.sidebar-menu a[href]').forEach(function (a) {
            var href = a.getAttribute('href') || '';
            if (!href || href === '#' || /^javascript:/i.test(href)) return;
            var span  = a.querySelector('span');
            var label = (span ? span.textContent : a.textContent).replace(/\s+/g, ' ').trim();
            if (!label) return;
            var k = label.toLowerCase() + '|' + href;
            if (seen[k]) return; seen[k] = 1;
            pages.push({ title: label, url: href.replace(BASE_URL, '') });
        });
    })();

    var items = [];      // flat list of {title,detail,url,type,icon} for keyboard nav
    var active = -1;
    var lastQ = '';
    var timer = null, ctrl = null;

    function esc(s){ return (s == null ? '' : String(s)).replace(/[&<>"]/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]; }); }
    function hi(text, q){
        var t = String(text || ''), i = t.toLowerCase().indexOf(q);
        if (i < 0 || !q) return esc(t);
        return esc(t.slice(0,i)) + '<mark>' + esc(t.slice(i,i+q.length)) + '</mark>' + esc(t.slice(i+q.length));
    }
    function openP(){ panel.classList.add('open'); input.setAttribute('aria-expanded','true'); }
    function closeP(){ panel.classList.remove('open'); input.setAttribute('aria-expanded','false'); active = -1; }

    function pageMatches(q){
        var out = [];
        for (var i=0; i<pages.length && out.length<6; i++){
            if (pages[i].title.toLowerCase().indexOf(q) !== -1)
                out.push({ title: pages[i].title, detail: '', url: pages[i].url, type: 'page', icon: 'fa-arrow-right' });
        }
        return out;
    }

    function render(q, groups, loading){
        items = [];
        var html = '';
        groups.forEach(function (g){
            if (!g.rows.length) return;
            html += '<div class="gs-group"><div class="gs-grouphd">' + esc(g.label) + '</div>';
            g.rows.forEach(function (r){
                var idx = items.length; items.push(r);
                html += '<a class="gs-item" data-idx="' + idx + '" data-type="' + esc(r.type) + '" '
                     + 'href="' + esc(BASE_URL + r.url) + '" role="option">'
                     + '<span class="gs-ic"><i class="fa ' + esc(r.icon || 'fa-angle-right') + '"></i></span>'
                     + '<span class="gs-tx"><span class="gs-t">' + hi(r.title, q) + '</span>'
                     + (r.detail ? '<span class="gs-d">' + esc(r.detail) + '</span>' : '')
                     + '</span></a>';
            });
            html += '</div>';
        });
        if (loading) html += '<div class="gs-note"><span class="gs-spin"></span>Searching students &amp; staff…</div>';
        else if (!items.length) html = '<div class="gs-empty">No matches for “' + esc(q) + '”</div>';
        panel.innerHTML = html;
        active = -1;
        openP();
    }

    function run(q){
        var mods = pageMatches(q);
        // Pages resolve instantly; entities need >=2 chars + a round-trip.
        var wantServer = q.length >= 2;
        render(q, [{ label: 'Pages', rows: mods }], wantServer);
        if (!wantServer) return;

        if (ctrl && ctrl.abort) { try { ctrl.abort(); } catch(e){} }
        ctrl = (window.AbortController ? new AbortController() : null);
        fetch(BASE_URL + 'admin/global_search?q=' + encodeURIComponent(q), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' },
            signal: ctrl ? ctrl.signal : undefined
        })
        .then(function (r){ return r.json(); })
        .then(function (res){
            if (q !== lastQ) return;                       // a newer keystroke won
            var rows = (res && res.results) || [];
            var students = rows.filter(function(x){ return x.type === 'student'; });
            var staff    = rows.filter(function(x){ return x.type === 'staff'; });
            var events   = rows.filter(function(x){ return x.type === 'event'; });
            render(q, [
                { label: 'Pages',    rows: pageMatches(q) },
                { label: 'Students', rows: students },
                { label: 'Staff',    rows: staff },
                { label: 'Events',   rows: events },
            ], false);
        })
        .catch(function (e){
            if (e && e.name === 'AbortError') return;
            if (q !== lastQ) return;
            render(q, [{ label: 'Pages', rows: pageMatches(q) }], false);  // keep pages usable
        });
    }

    function setActive(n){
        var els = panel.querySelectorAll('.gs-item');
        if (!els.length) return;
        if (active >= 0 && els[active]) els[active].classList.remove('active');
        active = (n + els.length) % els.length;
        els[active].classList.add('active');
        els[active].scrollIntoView({ block: 'nearest' });
    }

    input.addEventListener('input', function (){
        var q = input.value.trim().toLowerCase();
        lastQ = q;
        clearTimeout(timer);
        if (!q){ closeP(); return; }
        timer = setTimeout(function(){ run(q); }, 180);
    });

    input.addEventListener('keydown', function (e){
        if (e.key === 'ArrowDown'){ e.preventDefault(); setActive(active + 1); }
        else if (e.key === 'ArrowUp'){ e.preventDefault(); setActive(active - 1); }
        else if (e.key === 'Enter'){
            var els = panel.querySelectorAll('.gs-item');
            var el = (active >= 0 ? els[active] : els[0]);
            if (el){ e.preventDefault(); window.location.href = el.getAttribute('href'); }
        }
        else if (e.key === 'Escape'){ closeP(); input.blur(); }
    });

    input.addEventListener('focus', function (){ if (input.value.trim() && items.length) openP(); });
    document.addEventListener('click', function (e){ if (!box.contains(e.target)) closeP(); });

    /* ⌘K / Ctrl-K to focus from anywhere; "/" when not already typing. */
    document.addEventListener('keydown', function (e){
        if ((e.metaKey || e.ctrlKey) && (e.key === 'k' || e.key === 'K')){
            e.preventDefault(); input.focus(); input.select();
        } else if (e.key === '/' && !/^(INPUT|TEXTAREA|SELECT)$/.test((e.target.tagName||'')) && !e.target.isContentEditable){
            e.preventDefault(); input.focus();
        }
    });
})();
</script>